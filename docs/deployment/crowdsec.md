<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# CrowdSec Integration

This guide configures [CrowdSec](https://www.crowdsec.net/) to analyse OdinShield's
FrankenPHP access logs as an additional intelligence source. The deployment assumes
a Traefik reverse proxy already in front of OdinShield and a CrowdSec instance with
a Traefik bouncer already in place — CrowdSec decisions are enforced by Traefik;
this integration adds a second log source.

## Architecture

```text
            ┌─────────────────────────────────────────────────────┐
            │                       Host                          │
            │                                                     │
   internet │  ┌────────┐    ┌──────────┐                         │
   ────────►│  │Traefik │───►│ php      │  → /var/log/odinshield/ │
            │  └────┬───┘    │ (Caddy + │      access.log         │
            │       │        │  worker) │           │             │
            │       │        └──────────┘           │ ro          │
            │       │ access logs                   ▼             │
            │       │      ┌─────────────────────────┐            │
            │       └─────►│        CrowdSec         │            │
            │              │  - traefik parser       │            │
            │              │  - caddy parser (NEW)   │            │
            │              │  - shared scenarios     │            │
            │              └────────────┬────────────┘            │
            │                           │ decisions               │
            │                           ▼                         │
            │              ┌─────────────────────────┐            │
            │              │  Traefik bouncer        │            │
            │              │  (enforces bans)        │            │
            │              └─────────────────────────┘            │
            └─────────────────────────────────────────────────────┘
```

Both log sources feed into the same CrowdSec decision pool — bans triggered by
either parser are enforced by the Traefik bouncer for all subsequent traffic.

## Prerequisites

- OdinShield deployed via `compose.deploy.yaml` (Traefik mode).
- CrowdSec running as a separate container on the same host, with the
  Traefik bouncer already integrated.
- Root-equivalent access on the host to prepare the bind-mount directory.

## Step 1 — Prepare the host log directory

FrankenPHP runs as `www-data` (UID 33) inside the prod image. The host
directory must be owned by that UID so the container can write to it.

```bash
sudo mkdir -p /var/log/odinshield
sudo chown 33:33 /var/log/odinshield
sudo chmod 750  /var/log/odinshield
```

If you customise the path via `ODINSHIELD_LOG_DIR` in your deployment
`.env`, substitute the path in all three commands.

## Step 2 — Enable file logging in `compose.deploy.yaml`

The committed `compose.deploy.yaml` ships with the access-log configuration
**commented out** so deployments without CrowdSec keep their default
stdout behaviour. Uncomment the relevant block in the `php` service to
opt in:

```yaml
    environment:
      # ... existing env vars ...
      CADDY_SERVER_LOG_OPTIONS: |
        output file /var/log/caddy/access.log {
            roll_size 100mb
            roll_keep 10
            roll_keep_for 720h
        }
      CADDY_LOG_FORMAT_WRAP: "wrap json"
    volumes:
      - ${ODINSHIELD_LOG_DIR:-/var/log/odinshield}:/var/log/caddy:rw
```

Notes:

- The `environment:` block **must** use map form (`KEY: value`), not list
  form (`- KEY=value`). YAML literal-block scalars (`|`) only work with
  map form.
- `CADDY_LOG_FORMAT_WRAP` is a single-line value with one set of double
  quotes — never nest quotes.
- Indentation must be spaces only, never tabs.

Apply the change:

```bash
docker compose -f compose.deploy.yaml up -d --force-recreate php
```

Verify the container starts and the log file appears:

```bash
docker compose -f compose.deploy.yaml logs php | tail -20
ls -la /var/log/odinshield/         # access.log owned by uid 33
tail -f /var/log/odinshield/access.log
```

After the first HTTP request, JSON log entries appear in `access.log`.

## Step 3 — Mount the log directory into CrowdSec (read-only)

In the compose file that defines your CrowdSec container, add a read-only
bind mount of the same host directory:

```yaml
  crowdsec:
    image: crowdsecurity/crowdsec:latest
    # ... existing config ...
    volumes:
      - crowdsec_data:/var/lib/crowdsec/data
      - crowdsec_config:/etc/crowdsec
      - /var/log/traefik:/var/log/traefik:ro       # existing
      - /var/log/odinshield:/var/log/odinshield:ro # NEW
```

The `:ro` flag is critical — CrowdSec reads but never writes here.

## Step 4 — Add the acquisition source

Create `/etc/crowdsec/acquis.d/odinshield-caddy.yaml` on the CrowdSec
config volume (or append to the central `acquis.yaml`):

```yaml
filenames:
  - /var/log/odinshield/access.log
  - /var/log/odinshield/access-*.log    # rotated files
labels:
  type: caddy
```

The `type: caddy` label is what activates the Caddy parser — CrowdSec
matches log sources to parsers via this label, not via filename.

## Step 5 — Install the Caddy collection

```bash
docker compose exec crowdsec cscli collections install crowdsecurity/caddy
docker compose exec crowdsec cscli hub upgrade
docker compose restart crowdsec
```

The collection brings:

| Component | Purpose |
|---|---|
| `crowdsecurity/caddy-logs` | JSON parser for Caddy's default log format |
| `crowdsecurity/http-crawl-non_statics` | Crawler detection on non-static endpoints |
| `crowdsecurity/http-bad-user-agent` | Known malicious user agents |
| `crowdsecurity/http-probing` | Endpoint scans / 404 floods |
| `crowdsecurity/http-sensitive-files` | Access to `.env`, `.git`, etc. |
| `crowdsecurity/http-path-traversal-probing` | Path-traversal attempts |

## Step 6 — Install OdinShield-specific scenarios (optional)

The Caddy collection covers generic HTTP attacks but does not detect
application-level brute-force against the login form. Symfony's `form_login`
returns `302` for both successful and failed authentication, so failed
logins cannot be distinguished from successful ones via HTTP status alone.
A volumetric scenario fills the gap by counting `POST /login` per source IP:

```bash
docker compose cp ./crowdsec/odinshield-login-bf.yaml \
    crowdsec:/etc/crowdsec/scenarios/odinshield-login-bf.yaml
docker compose restart crowdsec
docker compose exec crowdsec cscli scenarios list | grep odinshield
```

The scenario file lives at [`crowdsec/odinshield-login-bf.yaml`](./crowdsec/odinshield-login-bf.yaml)
and documents calibration knobs (capacity, leakspeed, blackhole) inline.

**Prerequisite — trusted proxies must be configured.** Without
`CADDY_GLOBAL_OPTIONS` setting `trusted_proxies` + `client_ip_headers`
(see `compose.deploy.yaml`), the access log's `client_ip` field stays the
Traefik container IP — and this scenario would ban Traefik. Verify with a
fresh request that `client_ip` shows the external client address before
deploying the scenario.

### Optional companion scenarios

Three more OdinShield-tailored scenarios live alongside the login one.
Each addresses a gap that the generic `base-http-scenarios` / `http-cve`
collections do not cover.

| File | Detects | Type |
|---|---|---|
| [`odinshield-login-bf-slow.yaml`](./crowdsec/odinshield-login-bf-slow.yaml) | Low-and-slow brute-force on `/login` (> 20 attempts / ~100 min) | leaky |
| [`odinshield-http-5xx-flood.yaml`](./crowdsec/odinshield-http-5xx-flood.yaml) | Repeated 5xx responses from one IP — exploit probing signal | leaky |
| [`odinshield-symfony-debug-probing.yaml`](./crowdsec/odinshield-symfony-debug-probing.yaml) | Requests for `/_profiler`, `/app_dev.php`, … — scanner fingerprint, zero tolerance | trigger |

Install the same way:

```bash
for f in odinshield-login-bf-slow.yaml \
         odinshield-http-5xx-flood.yaml \
         odinshield-symfony-debug-probing.yaml; do
  docker compose cp ./crowdsec/$f crowdsec:/etc/crowdsec/scenarios/$f
done
docker compose restart crowdsec
docker compose exec crowdsec cscli scenarios list | grep odinshield
```

Recommended rollout: deploy `login-bf` first, observe one or two days of
real traffic to confirm no false positives, then add the three companion
scenarios.

## Verification

```bash
# Is the file being read?
docker compose exec crowdsec cscli metrics | grep -A2 odinshield

# Does the parser understand the format?
docker compose exec crowdsec cscli explain --file /var/log/odinshield/access.log --type caddy

# Current decisions
docker compose exec crowdsec cscli decisions list

# Live alerts
docker compose exec crowdsec cscli alerts list -l 20
```

## Rotation

Rotation is handled inside Caddy itself via the `roll_*` directives in
`CADDY_SERVER_LOG_OPTIONS`:

- `roll_size 100mb` — rotate when the current file exceeds 100 MB
- `roll_keep 10` — keep the 10 most recent rotated files
- `roll_keep_for 720h` — discard files older than 30 days

No host-side `logrotate` is required. CrowdSec automatically picks up
rotated files via the `access-*.log` glob in the acquisition source.

## Wiring decisions back to OdinShield

The Traefik bouncer enforces all CrowdSec decisions across the entire
host — including any bans triggered by the Caddy parser. **No additional
bouncer configuration is needed**: decisions originating from the new
log source flow through the existing enforcement path automatically.

If you ever want to **analyse only** (collect signals without enforcing
bans from Caddy-source events), add a profile in
`/etc/crowdsec/profiles.yaml` that matches the new source and short-
circuits with a zero-duration decision. In typical setups this is not
recommended — discarding good signals reduces protection.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `permission denied` opening `/var/log/caddy/access.log` | Host dir owned by root | `sudo chown 33:33 /var/log/odinshield` |
| `Unexpected next token after '{' on same line` | Env var collapsed to one line (`.env`-style or list-form `environment:`) | Use map-form `environment:` with `\|` literal block |
| Caddyfile validates, but no logs appear | `CADDY_SERVER_LOG_OPTIONS` empty in the running container | Verify with `docker compose -f compose.deploy.yaml config \| grep -A8 CADDY_SERVER_LOG` |
| CrowdSec doesn't see the file | Missing bind-mount or path mismatch | `docker compose exec crowdsec ls /var/log/odinshield/` |
| Parser doesn't recognise entries | Format isn't JSON | Confirm `CADDY_LOG_FORMAT_WRAP: "wrap json"` is set |

## References

- [CrowdSec documentation](https://docs.crowdsec.net/)
- [Caddy collection on the CrowdSec hub](https://app.crowdsec.net/hub/author/crowdsecurity/collections/caddy)
- [Caddy logging directives](https://caddyserver.com/docs/caddyfile/directives/log)
