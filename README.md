<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# OdinShield

Pragmatic vulnerability management — track software vulnerabilities across your asset
groups and prioritise remediation by an **effective risk score** derived from severity,
asset criticality, network exposure and data sensitivity (BSI-CS 093). OdinShield pulls
CSAF advisories automatically, enriches findings with exploitation data, and notifies the
responsible owners by mail.

The user interface is in German.

This guide covers running OdinShield in production with Docker. It ships as a single
container image (FrankenPHP) plus a worker and a PostgreSQL database, fronted by
[Traefik](https://traefik.io/) for TLS.

## Advisory sources

| Source | What it is | Scope |
|---|---|---|
| **CSAF** | Trusted Providers (BSI WID, CISA, Red Hat, Siemens, …), discovered via the BSI aggregator | Creates and updates vulnerabilities. Matches vendor/product **keys** (plus aliases) and the inventory **version** — no CPE |
| **FIRST EPSS + CISA KEV** | Daily enrichment — adds an EPSS score and the actively-exploited flag | Every open vulnerability with a CVE, whatever its source. Never creates a vulnerability |

The CSAF importer only ever touches software whose maintenance source is **Import**, and
it never auto-resolves a finding — a human always confirms remediation.

## Requirements

- A Docker host with Docker Compose v2
- A running **Traefik** instance on an external Docker network, with entrypoints
  `web` (80) and `websecure` (443) and a TLS certificate resolver
- The container image is built locally — see `.env.deploy.example`

## Deploy

```bash
# 1. Create the shared Traefik network once (skip if it already exists)
docker network create traefik

# 2. Configure
cp .env.deploy.example .env
$EDITOR .env

# 3. Start
docker compose -f compose.deploy.yaml up -d
```

On first start the `php` container automatically runs database migrations and sets up
the message queue (this can take 60–90 s before the app becomes healthy). Traefik then
requests a certificate and routes `https://<TRAEFIK_HOST>` to OdinShield.

### Minimum configuration

Generate the secrets and put them in `.env`:

```bash
openssl rand -hex 32                                 # → APP_SECRET
openssl rand -base64 32 | tr -d '=+/' | cut -c1-32   # → POSTGRES_PASSWORD
```

These five are **required** — Compose refuses to start without them, on purpose:

| Variable | Purpose |
|---|---|
| `ODINSHIELD_IMAGE_TAG` | Image version to run. Pin to a release; `latest` is rejected |
| `TRAEFIK_HOST` | The hostname **Traefik itself receives** (see the proxy note below) |
| `APP_SECRET` | 64-char hex — CSRF tokens, cookies, encryption. Never share or change casually |
| `POSTGRES_PASSWORD` | Database password |
| `MONOLOG_ERROR_TO` | Recipient of production error mails. Required so an unset value cannot silently deliver them to a placeholder address |

Commonly set as well:

| Variable | Purpose |
|---|---|
| `MAILER_DSN` | SMTP server for notifications, e.g. `smtp://user:pass@mail.example.com:587?encryption=tls`. `null://null` disables mail |
| `TZ` | Timezone, e.g. `Europe/Berlin` |

> **Image tags carry no leading `v`.** The git tag is `v2.0.0`, the image is `2.0.0` —
> `ODINSHIELD_IMAGE_TAG=v2.0.0` fails the pull with `manifest unknown`.

`.env.deploy.example` documents every remaining option, including the CSAF and
exploitation schedules, FrankenPHP worker tuning, and CrowdSec-ready access logs.

### If another proxy sits in front of Traefik

With a first-line reverse proxy or WAF ahead of Traefik, three settings that look
interchangeable must hold **different** values:

- `TRAEFIK_HOST` — the host **Traefik actually receives**. If the front proxy rewrites
  `Host` to an internal name, this is that internal name.
- `TRUSTED_HOSTS_REGEX` — must list the **public** name. Symfony trusts the immediate
  proxy and therefore prefers `X-Forwarded-Host`, where the front proxy puts the public
  name even when it rewrote `Host`. The value derived from `TRAEFIK_HOST` can only ever
  express one hostname, so set this explicitly:
  `^(public\.example\.com|internal\.example\.net|localhost)$`
- `APP_PUBLIC_URL` — the public base URL, or mail links point at a host outsiders
  cannot resolve.
- `TRUSTED_PROXIES` — the Traefik network's subnet **and** the front proxy's address,
  e.g. `172.22.0.0/16,10.20.0.2`. The default trusts only Traefik, so Symfony takes
  the front proxy for the client: every user shares one IP, and five wrong passwords
  for one account lock it out for everybody. `compose.frontproxy.yaml` shows how to
  read both values.

Getting this wrong produces **HTTP 400** (Symfony rejected the host — the response still
carries the app's `Content-Security-Policy` header) or **HTTP 404** (no Traefik router
matched — a bare `text/plain` body with no app headers). The distinction tells you which
layer to fix.

## Create the first administrator

```bash
docker compose -f compose.deploy.yaml exec php \
  bin/console odinshield:user:add <username> <first> <last> <email> '<password>' --admin
```

Users log in with their **username** (not email). Add `--must-change-password` to force
a password reset on first login.

## Upgrade

```bash
# Pin the new version in .env, then:
docker compose -f compose.deploy.yaml pull
docker compose -f compose.deploy.yaml up -d
```

Migrations run automatically when the new `php` container starts. Use `up -d`, not
`restart` — Compose re-reads the environment only when it recreates a container.

## Operate

### Import status

`/import-status` (admin only) lists every CSAF provider with its watermark, last success
and last error. It is the one place that reveals a provider that stopped feeding your
inventory — a source can fail for weeks without producing any other visible signal.

### Importer commands

```bash
# CSAF
… exec php bin/console odinshield:csaf:run [--sync]
… exec php bin/console odinshield:csaf:provider-list
… exec php bin/console odinshield:csaf:provider-toggle enable|disable <key>
… exec php bin/console odinshield:csaf:resume <key>          # clear the circuit breaker

# Exploitation enrichment (EPSS + KEV) — not shown on /import-status
… exec php bin/console odinshield:exploitation:run [--sync]
… exec php bin/console odinshield:exploitation:status
… exec php bin/console odinshield:exploitation:pause
… exec php bin/console odinshield:exploitation:resume

# Re-evaluate existing imported rows against the current matching (read-only)
… exec php bin/console odinshield:vulnerability:recheck
```

Both scheduled jobs pause themselves after repeated failures and mail an admin when they
do. `resume` clears the paused flag, the failure counter and the last error.

### Logs and backup

```bash
# Logs
docker compose -f compose.deploy.yaml logs -f php worker

# Database backup (data lives in the named volume "database_data")
docker compose -f compose.deploy.yaml exec database \
  pg_dump -U app app | gzip > odinshield-$(date +%F).sql.gz

# Restore
gunzip -c odinshield-2026-05-21.sql.gz | \
  docker compose -f compose.deploy.yaml exec -T database psql -U app app
```

The `worker` container delivers notification mails and drives the scheduled imports —
keep it running. Its `messenger:consume` list must include `scheduler_csaf` and
`scheduler_exploitation`; drop one and that job silently never fires, while
`debug:scheduler` still lists it — take the list from the current `compose.deploy.yaml`.

After restarting the `database` container, always restart `php` and `worker` too —
the database driver does not reconnect on its own.

## License

OdinShield is free software under the GNU Affero General Public License,
version 3 or later (AGPL-3.0-or-later) — copyright and license notice in
[LICENSE.md](LICENSE.md), full text in [LICENSE](LICENSE). Security findings:
[SECURITY.md](SECURITY.md). Project page: <https://www.odinshield.net>.

---

For development setup, architecture and contribution conventions, see
[CLAUDE.md](CLAUDE.md).
