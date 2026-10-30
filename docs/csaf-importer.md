<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# CSAF Importer

This document describes how the CSAF (Common Security Advisory Framework) importer
is wired together inside OdinShield. It is the developer-/operator-facing reference;
end-user-facing prose belongs in `docs/manual/`.

## Purpose

The CSAF importer pulls security advisories from one or more trusted providers
(BSI WID, Siemens ProductCERT, Red Hat Product Security, Hitachi Energy PSIRT, …),
matches the affected products against the local software inventory, and creates or
updates `Vulnerability` records for the matches. Per CSAF document one
`Vulnerability` is produced — multiple CVEs inside the same document collapse into a
single record (BSI-style bundling).

## Architecture

```text
                    ┌─────────────────────────────────────────┐
                    │   Symfony Scheduler (cron-driven)       │
                    │   src/Scheduler/CsafImportSchedule.php  │
                    │   "0 3,15 * * *" UTC                    │
                    └────────────────┬────────────────────────┘
                                     │ dispatches
                                     ▼
                    ┌─────────────────────────────────────────┐
                    │   src/Message/RunCsafImportMessage.php  │
                    └────────────────┬────────────────────────┘
                                     │ async/messenger transport
                                     ▼
                    ┌─────────────────────────────────────────┐
                    │   src/MessageHandler/                   │
                    │     RunCsafImportHandler.php            │
                    │   ──── invokes ────►                    │
                    └────────────────┬────────────────────────┘
                                     │
   ┌─── Console ───┐                 ▼
   │ odinshield:   │   ┌─────────────────────────────────────────┐
   │   csaf:*      │──►│   src/Service/Csaf/CsafImportRunner.php │
   │ src/Command/  │   │   • raiseMemoryLimitIfNeeded()          │
   └───────────────┘   │   • ensureBsiBootstrap()                │
                       │   • aggregatorExpander->expand()        │
                       │   • findActiveNotPaused() ────────┐     │
                       │   • for each provider:            │     │
                       │       runForProvider()            │     │
                       └─────────┬─────────────────────────┼─────┘
                                 │                         │
              ┌──────────────────┤                         │
              │                  │                         │
              ▼                  ▼                         ▼
   ┌──────────────────┐ ┌──────────────────┐  ┌────────────────────────┐
   │  src/Service/    │ │  src/Service/    │  │  src/Repository/       │
   │   Csaf/Fetcher/  │ │   Csaf/Parser/   │  │   CsafProviderState    │
   │                  │ │                  │  │     Repository.php     │
   │ • Provider       │ │ CsafDocument     │  └────────────┬───────────┘
   │   Metadata       │ │   Parser.php     │               │
   │   Fetcher.php    │ │ (JSON → DTOs)    │               ▼
   │ • Aggregator     │ │                  │   ┌────────────────────────┐
   │   Expander.php   │ │                  │   │  csaf_provider_state   │
   │ • RolieFeed      │ │                  │   │       (Postgres)       │
   │   Reader.php     │ │                  │   │  • last_fetched_at     │
   └────────┬─────────┘ └────────┬─────────┘   │  • consecutive_failures│
            │                    │             └────────────────────────┘
            ▼                    ▼
   ┌──────────────────┐ ┌──────────────────┐
   │  src/Service/    │ │  src/Service/    │
   │   Csaf/          │ │   Csaf/Dto/      │
   │   Exception/*    │ │  CsafAdvisory    │
   │ • Fetch          │ │  CsafVuln…       │
   │ • Parse          │ │  CsafProduct     │
   │ • Import         │ └────────┬─────────┘
   └──────────────────┘          │
                                 ▼
                  ┌──────────────────────────────────────────┐
                  │   src/Service/Csaf/Upserter/             │
                  │     VulnerabilityUpserter.php            │
                  │                                          │
                  │   for each advisory:                     │
                  │     1. computeWorstSeverity() ≥ minSev?  │
                  │     2. InventoryMatcher (keys+version)   │
                  │     3. findOneByCsafDocument(prov,docId) │
                  │     4. noteAvailableFix (fixed, no flip) │
                  │     5. software without AssetGroup → skip│
                  │        (Import gate is upstream: repo)   │
                  │     6. applyOverwritableFields           │
                  │        prependMitigationHistory          │
                  │     7. createVulnerability if new        │
                  └─────────────────────┬────────────────────┘
                                        ▼
                  ┌──────────────────────────────────────────┐
                  │   src/Repository/VulnerabilityRepository │
                  │     findOneByCsafDocument(prov, docId)   │
                  │   src/Entity/Vulnerability.php           │
                  │     • csafSources JSON                   │
                  │     • cveIds JSON                        │
                  └─────────────────────┬────────────────────┘
                                        ▼
                  ┌──────────────────────────────────────────┐
                  │           Postgres: vulnerability        │
                  │           vulnerability_software         │
                  │           vulnerability_occurrence       │
                  └──────────────────────────────────────────┘
```

## Detailed run flow

### Step 1 — Trigger

Two paths invoke the same entry point:

| Source | Path |
|--------|------|
| **Cron** | `CsafImportSchedule` dispatches `RunCsafImportMessage` → `RunCsafImportHandler` calls `$runner->runAll()` |
| **CLI**  | `odinshield:csaf:run --sync` calls `$runner->runAll()` directly (synchronous mode) |
| **CLI (async)** | `odinshield:csaf:run` dispatches `RunCsafImportMessage` to the async transport (same as cron) |

Both paths land in `CsafImportRunner::runAll()`.

### Step 2 — Runner initialisation

`CsafImportRunner::runAll()` performs three preparatory steps before doing any work:

```php
$this->raiseMemoryLimitIfNeeded();      // bumps PHP memory_limit to 512M
$this->ensureBsiBootstrap();            // seeds bsi_wid when the table is empty
$this->aggregatorExpander->expand(…);   // expands BSI aggregator into new provider rows
```

It then loads two lists *once* per run:

| Source | Data |
|--------|------|
| `CsafProviderStateRepository::findActiveNotPaused()` | Providers with `active=true AND paused=false` |
| `SoftwareRepository::findAllImportMatchable()` | Active, Import-maintained software of active vendors — the Import gate |

The software list is passed to every per-provider sub-run; no N+1. After the
per-provider loop finishes, the same list drives the
[Software review-timestamp bump](#step-7--software-review-timestamp-bump).

### Step 3 — Per-provider iteration

`CsafImportRunner::runForProvider()` is wrapped in try/catch:

```text
1. ProviderMetadataFetcher.fetch($state->providerMetadataUrl)
       → list of ROLIE feed URLs from provider-metadata.json

2. for each feed (ROLIE or changes.csv):
     readUpdatedSince($feedUrl, now − ledger_retention_days)
         → [(documentUrl, updatedDate), …]

3. first ledger run only: bootstrap — entries older than lastFetchedAt − 24 h are
   recorded as handled without download; ledgerSince is set

4. DocumentLedger::pending(entries, findHandled(provider), max_document_attempts)
     pending = no record | newer feed stamp | failed below the attempt limit
     oldest first, cut at max_documents_per_provider_run (the rest stays pending)

5. for each pending document:
     processOneDocument():
       a) httpClient->request('GET', $documentUrl)
       b) CsafDocumentParser.parse($json) → CsafAdvisory DTO
       c) rebuild check against the ledger record (#98)
       d) VulnerabilityUpserter.upsertAdvisory($advisory, $allSoftware)
     → ledger record: processed | rebuild | skipped | failed (attempts++)

6. On success:   state.lastFetchedAt = run start (display only), consecutive_failures = 0,
                 recentFeedEntries / pendingDocuments for /import-status
   On failure:   state.consecutive_failures++
                 ≥ failure_threshold → paused = true + AdminFailureNotifier mail

7. end of runAll(): prune ledger records with a feed stamp before the horizon
```

**Document ledger (#319).** `csaf_document_record` holds one row per provider and
document URL — feed stamp, outcome, attempts, last error, document id/version and the
last content change. It replaced the per-provider watermark, which lost documents
silently whenever a provider's stamps were not precise, monotonic and published in
order (#169 newest-first order, #313 BSI's date-only stamps). Backdated, reordered or
same-second stamps no longer matter; a failing feed simply contributes no entries, so
its documents stay pending until it recovers. A document failing
`max_document_attempts` times is given up and listed on `/import-status`; a newer feed
stamp starts it again. `odinshield:csaf:rewind <provider> --days=N` forgets the
records of the last N days.

**Per-feed fault tolerance.** When a single ROLIE feed returns 404 (BSI currently
ships three feeds that 404), the error is logged and the loop continues with the
next feed. The provider is only marked as failed when **all** of its feeds fail.

### Step 4 — DTO boundary

The parser-to-upserter boundary is mediated by three immutable DTOs in
`src/Service/Csaf/Dto/`:

```text
CsafAdvisory                          // one CSAF document
 ├─ documentId, version, title
 ├─ providerKey, providerDisplayName
 ├─ initialReleaseDate, currentReleaseDate
 ├─ sourceUrl                         // document URL
 ├─ aggregateSeverityLabel            // BSI: "hoch", "mittel", …
 ├─ documentNotes[]                   // BSI Summary/"Angriff"
 ├─ productsById: array<string, CsafProduct>
 └─ vulnerabilities[]: list<CsafVulnerability>

CsafProduct
 ├─ productId, name
 ├─ vendorToken, productToken         // normalised identity (CPE parts or branch names)
 ├─ range: VersionRange               // from the product_version[_range] branch name
 ├─ rawVersionText                    // as published, shown when unreadable
 └─ cpe                               // kept for display only, never compared as a whole

CsafVulnerability
 ├─ cve, title, cvssScore
 ├─ knownAffectedProductIds[]         // resolved against productsById
 ├─ fixedProductIds[]
 ├─ notes[]
 └─ remediations[]                     // pre-formatted lines, see below
```

Everything the parser hands to the data layer is a value object: no Doctrine
entities, no HTTP dependencies. Tests can construct DTOs directly without I/O.

**Remediation formatting (CSAF 2.0 §3.2.3.11).** A remediation block in CSAF
carries far more than just `details` — it has a `category` distinguishing a
vendor patch from a workaround from "no fix available", and optional `url`,
`date`, and `restart_required` metadata. The parser collapses every relevant
field into a single human-readable line of the form

```text
<Category>: <details>[ (available <date>)][ — <url>][ [Restart: <kind>]]
```

with `<Category>` rendered as `Vendor Fix`, `Mitigation`, `Workaround`,
`Optional Patch`, or `No Fix Available`. Example real-world output:

```text
Vendor Fix: Update to OPNsense 26.1.2 (available 2026-05-01T00:00:00Z) — https://opnsense.org/security/OPN-SA-26-1.html [Restart: machine]
Workaround: Disable the LDAP plugin until the patch is applied
No Fix Available: This product line is end-of-life as of 2025-12-31
```

Each line lands verbatim in the Vulnerability `mitigation` history block
prepended by `[<CVE>]` — see `VulnerabilityUpserter::prependMitigationHistory()`.

**BSI range nodes and the fix version (#134).** BSI WID is non-conformant in two ways:
`vulnerabilities[].remediations` is never populated, and `product_status.fixed` is never
populated either — advisories list affected products only via `known_affected`. The patched
version **is** in `product_tree`, side by side with the affected range node:

```text
product_tree.branches[0]   (vendor: "Open Source")
 └ branches[0]              (product_name: "OPNsense")
    ├ branches[0]           (product_version_range: "<26.1.8")
    │   product.product_id = "T053846"           ← only this is in known_affected, no CPE
    │
    └ branches[1]           (product_version: "26.1.8")
        product.product_id = "T053846-fixed"     ← not in product_status.fixed
        product_identification_helper.cpe = "cpe:/a:opnsense:opnsense:26.1.8"
```

The parser reads the affected range **from the range node's own name** (`<26.1.8` →
`VersionRange`), and lends the range node the *identity* of its sibling's CPE
(`opnsense`/`opnsense`) — never the sibling's version. The former heuristic (borrow the sibling
CPE, wildcard its version, promote the `-fixed` sibling, synthesise a "Vendor Fix" line) is gone:
it turned `<7.6.4` into "every FortiOS" (plan F1). The fix statement now derives from the range's
exclusive upper bound in the upserter (`VersionRange::fixedFrom()` → "fixed from 26.1.8"), which
is what the document actually says; explicitly listed `product_status.fixed` products still
count via `InventoryMatcher::matchKeys()`.

### Step 5 — `VulnerabilityUpserter::upsertAdvisory()`

The decision centre. One CSAF document yields exactly one Vulnerability row.

```text
1. SEVERITY THRESHOLD
   computeWorstSeverity($advisory)
       = max(CVSS across vulnerabilities[]) || aggregate_severity.text || Informational
   if < app.csaf.min_severity → return skipped=1

2. PRODUCT UNION + MATCH
   collect knownAffected + fixed across all vulnerabilities[]
   resolve product IDs → CsafProduct
   InventoryMatcher::match(affected)   → hits (keys equal AND version intersects), versionOutside, nearMisses
   InventoryMatcher::matchKeys(fixed)  → identity only
   $allInventorySoftware is SoftwareRepository::findAllImportMatchable() — the Import gate

3. NO MATCH → return 0 (advisory does not concern our inventory)

4. EXISTING RECORD?
   findOneByCsafDocument(provider, docId)
       SELECT * FROM vulnerability
         WHERE source = 'csaf'
           AND csaf_sources::jsonb @> '[{"provider":X,"docId":Y}]'

5. FIX STATEMENTS
   describeAvailableFixes(): "<sw>: fixed from <range upper bound>" per hit with an
   exclusive upper bound, "<sw>: fixed in <product>" per key-matched fixed product
   when existing AND fix lines exist → noteAvailableFix(): one-off English note appended to
     remediationComment ("Fix available per advisory …"), set patch_available
   → NEVER flips a status — a human confirms remediation (auto-resolve removed)
   → deduped per (provider, docId, version) → fixAvailable++ only on first note

6. NO hits → return fixAvailable (no insert)

7. SOFTWARE-WITHOUT-ASSETGROUP filter
   any hit whose software has no AssetGroup membership → log + skipped++

8. UPSERT BRANCH
   if existing:
       upsertCsafSource()              // bump on version change; always refresh affected[]
       applyOverwritableFields()       // cvssScore, advisoryUrl, patchAvailable, cveIds (on bump)
       prependMitigationHistory()      // new dated block on top, incl. derived fix lines (on bump)
       sourceDescription rewritten     // every run — carries the "## Matched because" block
       addSoftware() / addAssetGroup()
   else:
       createVulnerability() + em.persist()
```

### Step 6 — Persistence

The `Vulnerability` entity is persisted with the CSAF source marker in the
`csaf_sources` JSON column. On `flush()`:

```sql
INSERT INTO vulnerability
  (cve_id, cve_ids, title, severity, source, csaf_sources, …)
VALUES
  ('CVE-2026-44194',
   '["CVE-2026-44194","CVE-2026-45158"]',
   'OPNsense: Mehrere Schwachstellen …',
   'high',
   'csaf',
   '[{"provider":"bsi_wid","docId":"WID-SEC-W-2026-1475","version":"1","syncedAt":"…"}]',
   …);
```

Plus the cross-tables `vulnerability_software` (M:N) and
`vulnerability_occurrence` (one row per matched AssetGroup, with the denormalised
`effective_risk_score` maintained by `EffectiveRiskListener`).

Doctrine flushes happen periodically (every `app.csaf.batch_flush_size` advisories,
default 50) inside the per-provider loop; a final flush completes the provider.

### Step 7 — Software review-timestamp bump

After every provider has been processed, `runAll()` issues a single bulk
`UPDATE software SET last_reviewed_at = NOW() WHERE id IN (…)` covering every
**`MaintenanceSource::Import`**, CPE-bearing software that was passed to the
upserter. The reasoning: running the importer is the review event for Import
software — each CPE was cross-checked against every advisory feed we could
fetch, so the BSI-CS 093 §5.2 review timestamp can advance even when no advisory
matched. `Manual`/`None` software is excluded: it is reviewed by humans.

- **Audit log is bypassed on purpose.** A `Manual`-maintained software gets one
  audit entry per actual manual review; a per-software audit row per twice-daily
  CSAF run would drown that out. The bulk UPDATE goes through DBAL, not the EM,
  so the `dh/auditor` listener never fires.
- **Optimistic locking is preserved.** The bulk UPDATE only writes
  `last_reviewed_at`; `lock_version` is untouched, so a concurrent user editing
  Software's other fields in the UI is unaffected.
- **Import-only.** Only `MaintenanceSource::Import` software gets the timestamp.
  The dashboard widget filters by `maintenanceSource = Manual`, so Import
  software never appears as overdue and Manual software is only satisfied by an
  actual human review.

The net effect on the **Reviews overdue** dashboard widget:

| `maintenanceSource` | Widget shows when overdue? |
|---------------------|----------------------------|
| Manual              | Yes — human review required (importer never bumps it) |
| Import              | Never (importer is the review event) |
| None                | Never (deliberately not maintained) |

## Severity resolution

Severity is determined per advisory, not per CVE — the worst signal wins:

```text
computeWorstSeverity(CsafAdvisory):
  1. max(VulnerabilitySeverity::fromCvssScore(v.cvssScore)) over vulnerabilities[]
  2. else map aggregate_severity.text via mapAggregateLabel()
  3. else VulnerabilitySeverity::Informational
```

`mapAggregateLabel()` handles both English and German vocabulary BSI uses:

| Label (any case) | Maps to |
|------------------|---------|
| `critical`, `kritisch`, `sehr hoch` | `Critical` |
| `high`, `hoch` | `High` |
| `medium`, `mittel`, `moderate` | `Medium` |
| `low`, `niedrig` | `Low` |
| `informational`, `info`, `none`, `keine` | `Informational` |

Unrecognised labels return `null`, falling through to `Informational`.

The threshold for importing is `app.csaf.min_severity` (default `medium`). The
filter runs **before** CPE matching to avoid wasted work.

## Dedup mechanism

CSAF vulnerabilities are dedup'd on `(provider, documentId)`, not on CVE-ID. The
lookup uses a Postgres `jsonb @>` contains query:

```php
SELECT v.id FROM vulnerability v
WHERE v.source = 'csaf'
  AND v.csaf_sources::jsonb @> :needle::jsonb
LIMIT 1
```

where `:needle` is `[{"provider":"bsi_wid","docId":"WID-SEC-W-2026-1475"}]`. The
match ignores `version` and `syncedAt` so it stays stable across re-runs.

Three outcomes per re-import:

| Existing entry has | Action |
|--------------------|--------|
| same `version` | **no-op** (idempotent — `upsertCsafSource()` returns false, downstream fields are not touched) |
| different `version` | update overwritable fields + prepend new mitigation block |
| no entry for (provider, docId) | **insert** new Vulnerability |

For inventory CSAF growth in the thousands, a GIN index on the column is the
intended escalation path:

```sql
ALTER TABLE vulnerability ALTER COLUMN csaf_sources TYPE jsonb USING csaf_sources::jsonb;
CREATE INDEX idx_vulnerability_csaf_sources ON vulnerability USING gin (csaf_sources);
```

The query operator is unchanged; only the index changes. Not added today because
inventory size doesn't justify it.

## Matching (#134)

`src/Service/Csaf/Matching/`. Identity is `Vendor.vendorKey ∪ Vendor.aliases` against the
advisory's vendor token and `Software.productKey ∪ Software.aliases` against its product token,
compared for **equality after `KeyNormaliser::normalise()`** (lowercase, non-alphanumerics → `_`).
The advisory token comes from the leaf's CPE (`CpeTokenParser`, parts vendor/product only — the
part `a`/`o`/`h` is never compared), else from the pair all sibling CPEs agree on, else from the
normalised `vendor` / `product_name` branch names.

Version: `ComparableVersion::fromString(Software.version)` takes the leading dotted-numeric
part and denotes a prefix interval (`7.6` = `[7.6, 7.7)`); `VersionRange::fromText(branch name)`
parses canonical `vers:` and comparator text; `intersects()` decides. Unreadable range text is
fail-open (`parseFailure` carries the raw text into the "Matched because" block); an inventory
version without numeric start matches everything with reason `inventory-unparseable`.

Containment (`fortinet` ⊂ `fortinet_net`) is **never** a match — it is logged as a near-miss
with both spellings so an operator can add an alias.

## Provider state model

The `csaf_provider_state` table tracks per-provider sync metadata. It is **not
audited** — purely operational. Schema:

| Column | Purpose |
|--------|---------|
| `provider_key` | Primary key, e.g. `bsi_wid`, `siemens`, `redhat` |
| `display_name` | Human-readable name shown in CLI tables |
| `provider_metadata_url` | URL to `provider-metadata.json` |
| `last_fetched_at` | Start of the last successful run — display only since the ledger (#319); read once by the ledger bootstrap |
| `ledger_since` | Set by the ledger bootstrap; `NULL` = not yet on the ledger. Lower bound of the rebuild check |
| `recent_feed_entries`, `pending_documents` | Read volume of the last run for `/import-status` (#319) |
| `last_success_at` | Last successful run |
| `last_error_at`, `last_error_message` | Last error context |
| `consecutive_failures` | Auto-pause counter — when `≥ failure_threshold`, `paused = true` |
| `active` | Admin toggle via `odinshield:csaf:provider-toggle` |
| `paused` | Automatic pause flag — reset via `odinshield:csaf:resume` |
| `advisories_imported_total` | Lifetime imports counter |
| `created_at`, `updated_at` | `HasTimestamps` trait |

### Active vs. paused

The two flags are intentionally separate so that automated pauses don't kill an
operator's manual choice:

- `active = false` — admin disabled this provider (e.g., not relevant for our use)
- `paused = true` — automated pause after `failure_threshold` consecutive failures

A pause is cleared by `odinshield:csaf:resume <key>`; the active flag is
toggled by `odinshield:csaf:provider-toggle enable|disable <key>`.

## Configuration

All knobs live in `config/packages/csaf.yaml`:

```yaml
parameters:
    app.csaf.bsi_provider_metadata_url:  'https://wid.cert-bund.de/.well-known/csaf/provider-metadata.json'
    app.csaf.bsi_aggregator_url:         'https://wid.cert-bund.de/.well-known/csaf-aggregator/aggregator.json'
    app.csaf.ledger_retention_days:      90       # ledger horizon, also a new provider's first reach
    app.csaf.max_document_attempts:      5        # then a document is given up and listed
    app.csaf.registered_disabled:        ['security_access_redhat_com']  # registered switched off
    app.csaf.failure_threshold:          3        # auto-pause threshold
    app.csaf.http_timeout_seconds:       30
    app.csaf.batch_flush_size:           50       # Doctrine flush cadence
    app.csaf.memory_limit:               '512M'   # bumped at runAll() entry
    app.csaf.min_severity:               'medium' # drop threshold
    app.csaf.schedule_cron_default:      '0 3,15 * * *'
    app.csaf.schedule_cron:              '%env(default:app.csaf.schedule_cron_default:CSAF_SCHEDULE_CRON)%'
    app.csaf.admin_email:                '%env(default::CSAF_ADMIN_EMAIL)%'
```

The scheduler-cron is env-driven so each stage can run on a different cadence without
a code change: set `CSAF_SCHEDULE_CRON=0 6,18 * * *` (or whatever) in the deployment
`.env`; leave the env empty/unset to fall back to the committed default above. Symfony
Scheduler reads the schedule at worker boot — restart `php` + `worker` after changes
and validate via `bin/console debug:scheduler csaf` first (an invalid expression
crashes the worker on start-up).

**Timezone is always UTC.** `CsafImportSchedule::getSchedule()` passes
`new \DateTimeZone('UTC')` to `RecurringMessage::cron()` so the expression is
interpreted in UTC regardless of the container's `TZ` env. Without this pin, a
container running `TZ=Europe/Berlin` would silently shift `0 3,15 * * *` from
"03:00+15:00 UTC" (what we document) to "03:00+15:00 Berlin" — and after 13:00 UTC
the next firing would be 01:00 UTC the following morning instead of 15:00 UTC the
same day.

**Wiring between deployment `.env` and the Symfony runtime.** Two layers of env files
are involved and they serve different purposes:

| File | Read by | Purpose |
|------|---------|---------|
| `.env` at the repo root (created from `.env.deploy.example` on the prod host) | Docker Compose | variable substitution in `compose.deploy.yaml` |
| `environment/.env` (committed) | Symfony at runtime | declares the parameter binding + default value |

For a host-level value (`CSAF_ADMIN_EMAIL`, `CSAF_SCHEDULE_CRON`) to actually reach the
PHP/worker containers, **`compose.deploy.yaml` must map it** with the
`VAR: ${VAR:-default}` pattern on both the `php` and the `worker` service blocks. The
worker is critical: that's where Symfony Scheduler runs and needs to see the cron at
boot. Both env vars are wired up today; see the two `# CSAF importer …` blocks in
`compose.deploy.yaml`.

Plus audit-ignore in `config/packages/dh_auditor.yaml`:

```yaml
ignored_columns:
    - csafSources           # sync metadata, not business-meaningful change
    - csaf_sources
    - cveIds
    - cve_ids
```

Changes to any of the above require `bin/console cache:clear` and a `docker
compose restart worker` to take effect (Scheduler reads its cron expression at
boot).

### Admin email for failure notifications

`app.csaf.admin_email` is the recipient that `AdminFailureNotifier` writes to when
a provider crosses `failure_threshold` consecutive failures and gets auto-paused.
Configure it via the `CSAF_ADMIN_EMAIL` environment variable.

```bash
# environment/.env       (committed default — leave empty)
CSAF_ADMIN_EMAIL=

# environment/.env.local (per-stage override, not committed)
CSAF_ADMIN_EMAIL=security@example.com
```

For production set the value in the stage-specific dotenv (e.g.
`environment/.env.deploy.local`) or through the container orchestrator's secret
mechanism — the variable name stays the same.

**Behaviour:**

- **Value set** — the mailer (`MAILER_DSN`) sends a plain-text message naming the
  paused provider, the last error, and the resume command. The recipient is not
  an OdinShield account — any mailbox or group alias works.
- **Empty / unset** — the notifier silently no-ops and logs an `info` event
  (`CSAF provider paused but admin_email not configured.`). Provider state still
  flips to `paused = true`; just no email goes out.

After changing the value, restart both PHP and worker so the new env reaches the
Symfony container and the Messenger consumer:

```bash
docker compose exec php bin/console cache:clear
docker compose restart php worker
```

The email body content lives in `src/Service/Csaf/AdminFailureNotifier.php` and is
plain-text only (no Twig template) — see that file if you want to localise or
customise the wording.

## Console commands

```bash
# Trigger a run
docker compose exec php bin/console odinshield:csaf:run           # async (queues message)
docker compose exec php bin/console odinshield:csaf:run --sync    # synchronous

# Manage providers
docker compose exec php bin/console odinshield:csaf:provider-list
docker compose exec php bin/console odinshield:csaf:provider-add <key> <name> <url>
docker compose exec php bin/console odinshield:csaf:provider-toggle enable|disable <key>
docker compose exec php bin/console odinshield:csaf:resume <key>            # clears auto-pause

# Inspect the scheduler
docker compose exec php bin/console debug:scheduler csaf
```

## File inventory

```text
src/
├─ Command/
│   ├─ CsafRunCommand.php
│   ├─ CsafResumeProviderCommand.php
│   ├─ CsafProviderListCommand.php
│   ├─ CsafProviderToggleCommand.php
│   └─ CsafProviderAddCommand.php
│
├─ Entity/
│   ├─ CsafProviderState.php
│   └─ Vulnerability.php                    # extended with csafSources + cveIds
│
├─ Repository/
│   ├─ CsafProviderStateRepository.php
│   ├─ VulnerabilityRepository.php          # findOneByCsafDocument(), findOpenImported()
│   └─ SoftwareRepository.php               # findAllImportMatchable() — the Import gate
│
├─ Message/
│   └─ RunCsafImportMessage.php
│
├─ MessageHandler/
│   └─ RunCsafImportHandler.php
│
├─ Scheduler/
│   └─ CsafImportSchedule.php
│
└─ Service/Csaf/
    ├─ CsafImportRunner.php
    ├─ AdminFailureNotifier.php
    │
    ├─ Matching/
    │   ├─ KeyNormaliser.php                # equality after normalisation, near-miss containment
    │   ├─ ComparableVersion.php            # leading numeric part, prefix interval
    │   ├─ VersionRange.php                 # vers: + comparator text, fail-open
    │   ├─ CpeTokenParser.php               # vendor/product/version out of a CPE
    │   ├─ InventoryMatcher.php             # match() / matchKeys()
    │   ├─ InventoryMatch.php, MatchResult.php, MatchReason.php
    │
    ├─ Recheck/
    │   ├─ VulnerabilityRechecker.php       # odinshield:vulnerability:recheck (read-only)
    │   ├─ RecheckRow.php, RecheckVerdict.php
    │
    ├─ Dto/
    │   ├─ CsafAdvisory.php
    │   ├─ CsafProduct.php
    │   └─ CsafVulnerability.php
    │
    ├─ Exception/
    │   ├─ CsafFetchException.php
    │   ├─ CsafParseException.php
    │   └─ CsafImportException.php
    │
    ├─ Fetcher/
    │   ├─ ProviderMetadataFetcher.php      # provider-metadata.json → ROLIE feed URLs
    │   ├─ AggregatorExpander.php           # aggregator.json → provider registration
    │   └─ RolieFeedReader.php              # ROLIE feed → document URL list
    │
    ├─ Parser/
    │   └─ CsafDocumentParser.php           # JSON → DTO graph
    │
    └─ Upserter/
        └─ VulnerabilityUpserter.php        # domain decisions

config/packages/
├─ csaf.yaml                                # all parameters
└─ dh_auditor.yaml                          # csaf-* columns ignored

migrations/
├─ Version20260512153056.php                # csaf_provider_state + csaf_sources + mitre→manual
└─ Version20260512171434.php                # cve_ids

tests/
├─ Unit/Service/Csaf/
│   ├─ Matching/                            # KeyNormaliser, ComparableVersion, VersionRange, CpeTokenParser, InventoryMatcher
│   ├─ CsafDocumentParserTest.php           # incl. synthetic fixtures modelled on BSI 1492 / SICK / Siemens
│   └─ VulnerabilitySourceEnumTest.php
└─ Functional/Csaf/
    ├─ VulnerabilityUpserterTest.php
    └─ VulnerabilityRecheckTest.php
```

## Single-source-of-truth guarantees

Three properties hold by construction:

1. **All entry points converge.** Cron, async CLI, sync CLI, and tests all land in
   `CsafImportRunner` (for orchestration) or `VulnerabilityUpserter` (for the
   advisory→record decision). There is no shadow code path.
2. **DTO immutability.** Anything that crosses the parser → upserter boundary is a
   `readonly` value object. The upserter cannot mutate parsed data.
3. **Dedup is single-keyed.** `(provider, documentId)` is the only key. The
   upserter neither looks up by CVE nor by title; CVEs are descriptive metadata
   that go into `cveIds`.

## Maintenance-source gate

The importer only owns software whose vulnerabilities it is meant to maintain; human-maintained
software must not be touched by an automated run. Since #134 the gate is **upstream**:
`SoftwareRepository::findAllImportMatchable()` returns only active, `Import`-maintained software
of active vendors, and that list is what the runner hands to the upserter. The upserter has no
filter of its own — `VulnerabilityUpserterTest::testOnlyImportMaintainedSoftwareIsMatchable`
guards the query.

| Maintenance source | Import behaviour | Reason |
|--------------------|-----------------|--------|
| `Import` | **Always matched** | The importer is the maintenance path for this software. |
| `Manual` | **Never matched** | Vulnerabilities are curated by the responsible users; the importer stays out. |
| `None` | **Never matched** | Deliberately not maintained. |

**No auto-resolve.** The importer never flips a status — a human always confirms that a
patch is installed. When an advisory states a fix for a matched software (exclusive upper bound
of the affected range, or a `product_status.fixed` product with the same keys),
`noteAvailableFix()` leaves a one-off English note on the existing record (`Fix available per
advisory …`, deduped per provider/docId/version) and sets `patch_available = true`. The record
stays open until a user resolves it.

## Known limitations

- **Comparators are read as written, no branch semantics.** BSI's multi-branch
  Patch-Day advisories (`<7.2.12`, `<7.4.9`, `<7.6.4` as siblings) mean "per
  branch", but `<7.6.4` numerically also covers a patched 7.4.9. Accepted in
  plan decision D1/D5; the "Matched because" block makes it visible. A
  sibling-aware lower bound is a possible follow-up, not implemented.
- **Relationship products are not matched** (D9). Red Hat's "package on
  platform" model yields nothing; the memo is package-level matching via purl
  if a RHEL product ever enters the inventory.
- **Directory distribution is read, but not exhaustively.** Providers publishing
  via `distributions[].directory_url` instead of ROLIE are handled by
  `Fetcher/ChangesCsvReader` (`changes.csv`, paths resolved relative to the
  directory URL). Two limits remain: a per-provider document budget of
  `app.csaf.max_documents_per_provider_run` (750), so one very large provider
  cannot starve the others, and VEX sub-trees excluded by configuration
  (`/csaf/v2/vex/`) — Red Hat's VEX directory alone held 38,082 documents in a
  90-day window against 25,288 advisories, and without the exclusion the reader
  would follow it automatically. Note that a provider may offer *both*
  distribution types while carrying TLP labels on the ROLIE feed only.
- **No CVSS v4.** v3.1 is preferred, v3.0 is the fallback. Advisories shipping
  only CVSS v4 land at the document-level `aggregate_severity` fallback, or
  `Informational` when neither is present.
- **JSON column without index.** `csaf_sources` uses Postgres `JSON` not `JSONB`.
  Lookups work via cast on demand; switch to `JSONB` plus GIN when the import
  history crosses a few thousand rows.
- **Severity threshold has no retro-effect.** Raising `min_severity` does not
  delete or close already-imported records; it only filters future imports. The
  upserter's fix-available note is also skipped for sub-threshold advisories.
- **No OpenPGP signature verification — deliberately, not by omission**
  (Issue #7, closed
  won't-do on 2026-09-11). CSAF Trusted Providers sign both
  `provider-metadata.json` and the individual advisories with OpenPGP keys whose
  fingerprints are listed in `public_openpgp_keys[]`. A survey of all 17
  registered providers on 2026-09-11 found that they do: 14 of 15 readable
  providers declare keys, every provider that served a document also served an
  `.asc` sibling, and 8 of 8 sampled signatures verified as good (BSI, Siemens,
  SICK, ABB, KUNBUS, CERT@VDE, Red Hat, Stackable). The single non-signer was
  `innomic`, which declares `role: csaf_provider` rather than *trusted
  provider*.

  Verification was still rejected, because it would not buy what the threat
  model asks for. The key list and the advisory travel over the *same* TLS
  connection from the *same* host: an attacker able to forge one forges the
  other, so an unpinned check validates the chain against itself. Real
  protection begins with a pinned fingerprint per provider (trust on first use,
  alarm on key change) plus a decided policy for unsigned sources, subkey
  handling, an operational bypass and tests — and that cost was judged
  disproportionate here. Anything in between would be security theatre.

  Two findings are worth keeping for whoever revisits this: signatures are
  frequently made by a **subkey** of the fingerprint named in the metadata
  (measured for SICK, ABB and CERT@VDE), so a fingerprint equality test would
  reject legitimate documents — import the key and let the OpenPGP
  implementation resolve the relation. And hash siblings are not uniformly
  reachable (`.sha256` returns 403 at Siemens and Schneider, `.sha512` returns
  404 at Red Hat), so no fixed variant can be assumed.

  Reopen if a provider is actually compromised, if the CRA preparation requires
  integrity checking of ingested security data, or if fingerprint pinning gets
  built for another reason — verification is the cheap part once it exists.

## Tests

The behaviour is locked down by:

| File | Coverage |
|------|----------|
| `tests/Unit/Service/Csaf/Matching/*` | KeyNormaliser, ComparableVersion, VersionRange (real BSI/SICK/Siemens strings), CpeTokenParser, InventoryMatcher (hit reasons, version-outside, near-miss log, aliases) |
| `tests/Unit/Service/Csaf/CsafDocumentParserTest.php` | Real-world BSI/GStreamer/OPNsense fixtures plus the live documents WID-SEC-W-2026-1492, SICK sca-2026-0013, Siemens SSA-019113; schema rejection, status filtering, identity/range extraction, relationship exclusion |
| `tests/Unit/Service/Csaf/VulnerabilitySourceEnumTest.php` | Regression guard for the Mitre→Manual migration |
| `tests/Functional/Csaf/VulnerabilityUpserterTest.php` | End-to-end scenarios — insert, idempotency, version bump, fix-available note (no status flip) + note dedup, no resolved mail, maintenance-source gate (repository query), software-without-AssetGroup, live BSI/SICK/Siemens documents, version outside range, unparseable inventory/advisory versions, aliases and near-misses, multi-CVE bundling, aggregate-severity fallback, severity threshold both passing and dropping |

Run subsets:

```bash
docker compose exec php bin/phpunit tests/Unit/Service/Csaf/
docker compose exec php bin/phpunit tests/Functional/Csaf/
```
