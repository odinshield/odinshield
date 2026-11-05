# CLAUDE.md

<!-- Maintainers: keep this file under ~230 lines (Claude Code docs: <200 per file, it is loaded on every
turn). Area-specific detail belongs in a path-scoped rule under .claude/rules/; host-only setup in
docs/internal/DEV-SETUP-HOST.md. Correctness-critical domain rules that cut across areas stay here.
HTML comments like this one are stripped before Claude sees the file. -->

## Project

**OdinShield** is a vulnerability management system for tracking software vulnerabilities across asset groups. Symfony 8.1, Doctrine ORM 3.6+, PHP 8.5+, PostgreSQL (no `UNSIGNED` or other MySQL-only features), Hotwire (Stimulus + Turbo), AssetMapper (no Node build step).

- **Languages:** domain language German; code identifiers and Symfony conventions English. Claude artifacts (`CLAUDE.md`, `.claude/rules|skills|agents/*`, internal docs) are English. User-facing text stays German where the domain requires it: `translations/*.de.yaml`, `docs/manual/de/`, reports on German chapters.
- **Talking to Andre:** German, concise; technical terms and commands stay in the original.
- **Secrets:** never in the repository, docs, commits, chat output or command lines — name only where they are stored. A command that needs one reads it from an env var or file Andre sets; check without revealing (length, hash prefix, HTTP status).

## Where commands run

Claude works in the compose service `claude` (`make claude`; design in `docs/internal/CLAUDE-CONTAINER.md`): non-root, **no `docker` CLI**, no Gitea push credentials, private networks blocked except the stack's own network and git01:443; public sites (upstream docs, advisory feeds, package registries) are reachable.

- **In the claude container:** `make <target>` runs everything directly (`IN_CONTAINER`). Without a target, call PHP tools directly: `php bin/console …`, `bin/phpunit …`, `vendor/bin/phpstan …`. The dev database and services are reachable as in the `php` container.
- **On the host** (Andre, or a host Claude session): the same `make` targets wrap `docker compose exec -T php …`; without a target use `docker compose exec php <command>`. Project PHP tooling never runs on the host itself (host PHP may be 8.4). First-time host setup: `docs/internal/DEV-SETUP-HOST.md`.
- Commands below are written for the container; on the host prefix `docker compose exec php`.

**Division of work.** Container (Claude): `make fix`, `make verify`, `make ci` (stamps `.ci-ok`), `make release v=…`, local git (commit, branch, tag; `.git/config` and `.git/hooks` are read-only → create branches with `--no-track`; `git add -p` does not work). Host (Andre): `make push` (branch + PR), `make ship v=…` (release), image builds, anything with a token. Those check nothing themselves and refuse a HEAD without the stamp; `make push-check` shows read-only what `make push` would send.

**Gitea issues from the container:** `$GITEA_ISSUE_TOKEN` (`claude-bot`, scope `write:issue` only; empty when `pass` was locked) for issues, comments, labels and milestones via `curl -H "Authorization: token $GITEA_ISSUE_TOKEN"` — nothing outside issues. Never print, echo or log the token; check it only by HTTP status or length. Only on Andre's word.

## Git, commits, issues

- **Branches:** `typ/<nr>-kurzname` from `main/main` (remote is named `main`, not `origin`). At most one open local branch — `.ci-ok` holds a single stamp; stack small changes on the open branch until Andre has pushed it. Merging into `main` is Andre's. Local `main` moves only by `git pull --ff-only`.
- **Commits:** prefix `FIX` · `ADD` · `CHORE` · `DOCS` · `RELEASE`, German subject with area and issue, e.g. `FIX CSAF-Import: … (#313)`; blank line; body explaining why; `Closes #n` where it applies; the attribution line. The prefix decides the public release notes (`release` skill); a `FIX`/`ADD` that is internal gets the trailer `Release-Note: intern`; a security-relevant commit (any prefix) gets `(Security)` in the subject — it marks the release as a security update in `version.json` (#223). Check `git log --oneline -1` before `make ci` — without the blank line the body lands in the subject.
- **Gitea issues:** sections `## Problem / Motivation`, `## Vorschlag / Lösung`, `## Betroffene Stellen`, `## Akzeptanzkriterien`; title with the same prefixes; labels by id.
- `git push` only when the current message asks for it, as a plain `git push` alone on the line (a push-gate hook checks for this).

## Knowledge store

Session learnings live **only in this repository** (decided 2026-10-21 — no Claude Code auto-memory under `~/.claude/projects/`): one fact per file in `.claude/memory/` (frontmatter `name`/`description`/`metadata.type`), one index line per file in `MEMORY.md`. Index lines without a file are from the lost pre-2026-10-21 store; the line is all that remains. `MEMORY.md` and `.claude/memory/` changes go in their own `DOCS Memory: …` commit on the open branch — never mixed into a feature commit, never on local `main`.

## Working with Claude Code

- Change files only with the Edit/Write tools — never via `python3 - <<EOF`, `sed -i` or `cat > … <<EOF`.
- Logs and scratch output under `var/` in the repo (e.g. `var/log/ci.log`), never `/tmp`. For background tasks, wait for the completion notification instead of reading the task output file.
- **Gates:** run `make ci` / `make verify` as a background Bash task (`make ci > var/log/ci.log 2>&1`). On success read only the last line; on failure only the failing step's section, quoted verbatim. A small-model subagent only for triaging many failing tests.
- Before handing Andre a command or SQL for his terminal, run it locally against the dev schema.
- Do not install global tools (`npm install -g`, `apt`, `pip --user`) without asking.
- **Host sessions only** (permission parser): start in the repo root; no `cd …;`/`cd … &&` prefix; network commands (`git fetch`/`pull`/`push`, `make push`, `make release-preflight`, `make gitea-release`, `deploy/gitea-*.py`) alone on their line — no pipe, `;`, `&&`, `$(…)` or redirection; no leading variable assignments (`SSL_CERT_FILE=… cmd`). A `CERTIFICATE_VERIFY_FAILED` or missing token means the setup is broken — stop and report, never pass the CA or token inline.

## Commands

```bash
make start | make stop | make logs | make status   # stack (FrankenPHP + worker + Postgres + Mailpit); status also shows local-only commits
make fix                 # all auto-fixers: fix-php + asset-fix + shell-fix + md-fix
make verify              # lint-yaml, lint-php, stan, twig-lint, trans-lint, lint-stimulus, test — no stamp
make ci                  # container only: audit, asset-lint, reuse/shell/docker/md/secrets-lint, lsp-check + verify;
                         # stamps a clean tree into .ci-ok (memory-only branch: secrets-lint + reuse-lint only)
make test | test-unit | test-functional | test-coverage   # coverage → var/coverage/index.html
bin/phpunit tests/path/to/FooTest.php                     # single file; --filter testMethodName for one test
make stan | lint-php | fix-php                            # PHPStan level 8, @Symfony cs-fixer
make audit               # composer audit --locked (network); accepted advisories: composer.json config.audit.ignore with a reason
make lsp-check           # symfony lsp:check, baseline tools/symfony-lsp-baseline.json; templates/vendor/ is a blind spot
make reuse-lint | shell-lint | docker-lint | md-lint | secrets-lint | asset-lint | twig-lint | trans-lint | trans-list
make cc                  # cache:clear + warmup (after config changes)
make migration           # diff + migrate;  make migration-dev = DEV ONLY, drops schema and regenerates one migration
make test-db             # create + migrate app_test (fresh volume)
make asset-install | asset-compile;  php bin/console debug:asset-map
make outdated            # outdated importmap/Composer packages and pinned tools with release dates (informative)
make sf                  # list console commands
```

- **Linter exceptions:** `# hadolint ignore=` with a reason; gitleaks false positives get `gitleaks:allow` on the line; markdownlint excludes agent memory, `MEMORY.md`, `.claude/agents`, `.claude/skills`.
- **Dependency bumps** only to versions at least two weeks old; a security release that reaches us is the exception (decide per advisory: affected versions, reachability in our code).
- **MakerBundle** (`make:controller`, `make:entity`, `make:form`, `make:stimulus-controller`) needs an interactive prompt — host only.
- **Release & production image:** `release` skill (`make release` here → `make ship` on the host). Smoke-test build: `make build-local` (host).

**Seeding / users** (no make target):

```bash
php bin/console odinshield:user:add <username> <first> <last> <email> <password> [--admin] [--must-change-password]
php bin/console odinshield:user:seed [--count=50 --password=Test1234! --must-change-password]
php bin/console odinshield:asset-group:seed        # asset types + asset groups
php bin/console odinshield:user-group:seed         # needs users AND asset types — after the two above
php bin/console odinshield:vulnerability:seed
php bin/console cache:pool:clear cache.rate_limiter   # clears login throttling (5 / 15 min)
```

`user:seed` gives each user one **random** role (admins included) and the shared dev password `Test1234!`. There is no CLI password reset: log in as a seeded `ROLE_ADMIN` and reset it in user management. After a `database` container restart always `docker compose restart php worker` too (DBAL 4 has no auto-reconnect).

## Architecture conventions

`public/index.php` → `src/Kernel.php` → Router → `src/Controller/` → Twig (`templates/`). Environment files live in `environment/` (`extra.runtime.dotenv_path`); secrets in `environment/.env.local` (never committed).

- **Controllers** stay thin and delegate to services; UUID routing, pagination and list params → `controller-conventions` rule.
- **Entities** use PHP attribute mapping and the traits `HasUuid` (UUIDv7 `uuid`), `HasTimestamps` (needs `#[ORM\HasLifecycleCallbacks]`), `HasEditedBy`.
- **Repositories** hold QueryBuilder/DQL; paginate with Doctrine's `Paginator`, not KnpPaginatorBundle.
- **Commands** are prefixed `odinshield:` and use the Symfony 8.1 **invokable** style (`#[AsCommand]` + `__invoke()` with typed `#[Argument]`/`#[Option]` and an injected `SymfonyStyle`), not `configure()`/`execute()`. Related commands sharing dependencies are one class with `#[AsCommand]` on methods (e.g. `CsafProviderCommands`). Intentionally classic `extends Command`: `OdinshieldAddUserCommand` (interactive wizard). Avoid `#[MapEntity]` on arguments where a friendly "not found" message is wanted — it throws instead of returning null.
- **Listeners/subscribers:** `#[AsDoctrineListener]` / `#[AsEventListener]` in `src/EventListener/`, `EventSubscriberInterface` in `src/EventSubscriber/`.
- **Enums** (`src/Enum/`) are backed; all expose `label()`, most `color()` returning Tabler color names.
- **Security conventions:** security-sensitive decisions carry `// SEC-NNN:` comments — read the comment before changing nearby logic.

## Domain rules

**Roles** (constants on `User`): `ROLE_USER`, `ROLE_ADMIN`, `ROLE_SOFTWARE_OWNER`, `ROLE_HARDWARE_OWNER`, `ROLE_ISB`. Login by username, not email. `User::getRoles()` always appends `ROLE_USER` — don't store it or offer it in the role form.

- `ROLE_ISB` — full access to all vulnerabilities; the **only** role that may delete (`VulnerabilityController::delete`) or close (transition to `Closed`, 4-eyes principle).
- `ROLE_ADMIN` — no vulnerability or inventory rights of its own, but deliberately all-powerful (#196): may give any account any role, its own included, and `/audit` shows all vulnerability content unfiltered. Controlled by the audit trail, not by code → `security-and-auth` rule.
- `ROLE_SOFTWARE_OWNER` — bypass for view **and** modify. `ROLE_HARDWARE_OWNER` — bypass for **view only** (intentionally absent from `VulnerabilityAccess::MODIFY_BYPASS_ROLES`).
- Any `ROLE_USER` can create a vulnerability; an empty `responsibleUser` auto-assigns the creator (`VulnerabilityController::new`).
- Per-entity `responsibleUser` on `Software`, `AssetGroup`, `Vulnerability`, plus UserGroup membership via `AssetType`, grant scoped view/modify without a bypass role. `VulnerabilityAccess` is the single resolver for these rules → `repository-conventions` rule.

**Statuses:** `VulnerabilityStatus` `Open`, `InProgress`, `Resolved`, `Accepted`, `Deferred`, `Closed`; `isTerminal()` = `Resolved`/`Accepted`/`Closed`; `Closed` requires `ROLE_ISB`. The "active set" for dashboard counts is `{Open, InProgress, Resolved, Deferred}`. Two-axis semantics (vulnerability lifecycle vs. occurrence truth, terminal guard #9) → `vulnerability-status-lifecycle` rule.

**Effective risk (BSI-CS 093 §3.3):** per occurrence `severity.weight × assetGroup.criticality.weight × exposure.weight × dataSensitivity.weight`, **× 2 when `Vulnerability.isActivelyExploited`** (`VulnerabilityOccurrence::ACTIVE_EXPLOITATION_FACTOR`, #125 — deliberate deviation from BSI, band thresholds unchanged, counts whether KEV or a human set the flag; EPSS is deliberately not a factor); range 1–480. Denormalised onto `VulnerabilityOccurrence.effectiveRiskScore` by `EffectiveRiskListener` (→ `doctrine-listeners` rule). Per vulnerability = `MAX(occurrence.effectiveRiskScore)`; `VulnerabilityController::index` sorts on it, default `effectiveRisk desc`. Bands (`EffectiveRiskBand`): Critical ≥ 120, High ≥ 60, Medium ≥ 20, Low < 20.

**BSI thresholds and software review (BSI-CS 093 §5.2):** `config/packages/bsi_thresholds.yaml` → `%app.bsi.eos_warn_days%` (90) and `%app.bsi.review_stale_days%` (30), injected into `DashboardController`. `Software.maintenanceSource` is `Import` (only these are touched by the importer), `Manual` (drives the review-overdue widget) or `None` (not maintained, excluded). `lastReviewedAt` moves by `POST /software/{uuid}/mark-reviewed` (CSRF, optimistic-lock retry, audit log) or by `CsafImportRunner::runAll()`, which bulk-updates Import software via DBAL after a successful cycle (audit log bypassed on purpose). Widget visibility is decided **only** in the controller (`$showSoftwareWidgets`: ISB, both owner roles, asset-group-responsible users see all software; software-responsible users their own); the template links per row with `is_granted('SOFTWARE_VIEW', sw)` and gates actions with `SOFTWARE_MODIFY` (#299).

**CSAF import invariants** (details, pipeline and pitfalls → `vulnerability-importers` rule; design → `docs/internal/PLAN-IMPORT-REDESIGN-2026-09-11.md`, document ledger → `docs/internal/PLAN-CSAF-LEDGER-2026-10-29.md`):

- Only `MaintenanceSource::Import` software is matched; the gate is `SoftwareRepository::findAllImportMatchable()`, the upserter trusts its list (#134).
- **Never auto-resolves.** A reported fix appends a one-off English note to `remediationComment` and sets `patchAvailable`; status stays. Human-edited fields the importer writes into: `remediationComment` (notes) and `mitigation` (history block on version bumps); `description` is never touched; `sourceDescription` (ends with `## Matched because`) is rewritten every run.
- Severity override (#50): a human severity with `severityOverrideReason` survives version bumps unless the source severity rises above both its previous value and the human rating. CVSS is never overridden. Vendor correction (#57) only appends a note, never closes or unlinks.
- Matching = vendor/product **keys** (+ aliases, normalised by `KeyNormaliser`) and the leading numeric **version**, no CPE field; inventory `7.6` is reported against `<7.6.4` on purpose (D1, in the manual). Relationship products (Red Hat) are not matched (D9).
- What a run reads is decided by the **document ledger** (`csaf_document_record`, #319), not a watermark: every feed entry within 90 days without a record, with a newer stamp than its record, or failed below 5 attempts. Provider stamps are never compared with a run time (BSI stamps midnight but publishes during the day, #313). `lastFetchedAt` is display only; `odinshield:csaf:rewind <provider> --days=N` forgets the records of N days so they are re-read.
- Exploitation enrichment (`src/Service/Exploitation/*`, daily): FIRST EPSS stored as **percent** 0–100, CISA KEV flips `isActivelyExploited` only false → true, for every non-terminal vulnerability with a CVE; it never creates a vulnerability.

**Dashboard counts:** the cards Open / Critical (= severity `{Critical, High}`) / In Progress show the global figure left and the figure **strictly assigned to the logged-in user** (`v.responsibleUser = :assignee`) right. `countInActiveStatuses()`, `countHighSeverityInActiveStatuses()`, `countInProgress()` take `?User $assignee` (`null` = global). This matches the navbar bell (`countNewAssignedTo`) and the list filter `?assignee=me` — all three must agree. Do not switch it to `VulnerabilityAccess::applyViewFilter()`.

**Global figure for everyone (#67, decided 2026-09-11):** the left-hand number and the monthly chart are shown to every authenticated user, including one who can see nothing else — the aggregate standing is treated as internally open. Two obligations: (1) the vulnerability list explains an empty page — `VulnerabilityController::index` passes `empty_reason` `filtered` / `no_access` / `no_data`, derived from `VulnerabilityRepository::countVisibleTo()`, **not** from the filter parameters (the global cards link with `status=active`, so "reset filters" must not be offered to someone who may see nothing); a global card must never link into an unexplained empty list. (2) The user manual states it (#61). Alternatives if ever reversed: cards only with a visible inventory, or the global figure bound to ISB/admin/owner roles.

## Path-scoped rules (`.claude/rules/`)

Load automatically when a matching file is read or edited — read the rule before working outside its scope on the same topic.

| Rule | Loads for | Covers |
|---|---|---|
| `controller-conventions` | `src/Controller/` | UUID routing, pagination, list params, form gotchas |
| `repository-conventions` | `src/Repository/`, `src/Security/` | `VulnerabilityAccess` single resolver, `applyViewFilter` DQL, UserGroup chain |
| `security-and-auth` | `src/Security/`, `src/EventSubscriber/`, `src/EventListener/` | voter signature, auth flow, CSP (`SecurityHeadersListener`) |
| `validator-conventions` | `src/Validator/` | translation domain, password policy |
| `doctrine-listeners` | `src/EventListener/`, `src/Entity/` | denormalisation cascade, `HasTimestamps` callbacks |
| `audit-trail` | `src/Entity/` | `#[Auditable]`, ignored columns, `audit_entries()` |
| `vulnerability-status-lifecycle` | status enum, vulnerability entity/controller/forms | two-axis status semantics |
| `vulnerability-importers` | `src/Service/Csaf/`, `src/Service/Exploitation/` | CSAF pipeline, matching, upserter, budget, BSI stamps |
| `hotwire-turbo` | `templates/`, `assets/controllers/` | Turbo Streams, modals, flash → toast |
| `twig-coding-standards` | `templates/**/*.twig` | `trans` filter, icons, accessibility, form includes, theme cookie |
| `live-components` | `src/Twig/Components/`, `templates/components/` | `ROLE_SOFTWARE_OWNER` gate, `SoftwareForm` |
| `css-coding-standards`, `js-coding-standards` | `assets/**` | Biome-enforced asset standards |
| `testing` | `tests/` | private services, inverse-side hydration, requests bypass Turbo |
| `user-manual` | `docs/manual/`, `ManualController`, `PagefindAssetController`, `src/Markdown/` | Markdown rendering, `MANUAL_VERSION`, Pagefind, `manual-qa-diataxis` |
| `deployment-ops` | `compose*.yaml`, `Dockerfile`, `frankenphp/**` | topology, env, worker consume list, FrankenPHP worker mode |

## Do not edit

`bin/`, `public/` (Symfony), `vendor/` (Composer), `var/` (cache/logs), `composer.lock` / `symfony.lock` (only via Composer).

## PHP code standards

`@Symfony` CS-Fixer (risky on) + PHPStan level 8, enforced by `make fix` / `make verify` (formatting, Yoda, strict comparisons, trailing commas, blank line before `return`, no `else` after `return`/`throw`). Not caught by tooling:

- `declare(strict_types=1)` in every file; constructor property promotion; no separator comments in classes.
- Enum cases `UpperCamelCase`; `sprintf()` for exception messages; `get_debug_type()` for class names.
- Naming: `camelCase` methods/vars · `UpperCamelCase` classes · `SCREAMING_SNAKE_CASE` constants · `snake_case` config/routes/Twig vars/templates/assets · suffixes `*Controller`/`*Type`/`*Repository`/`*Command`/`*Listener`/`*Exception`/`*Trait`/`*Interface`/`*Test`.

**SPDX header (REUSE, #244, checked by `make reuse-lint`):** every PHP file under `src/` and `tests/` gets a two-line SPDX block after `declare(strict_types=1);` — added by `make fix-php` (`header_comment` in `php-cs-fixer.php`); never hand-write or tweak it:

```php
/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
```

Every other file with comment syntax (Twig, JS, CSS, YAML, Markdown, shell, XML, INI, `.env`) carries the same two lines as its own header — no auto-fixer, copy from a sibling (Twig uses `{# … -#}`, Markdown an HTML comment). Files without comment syntax or rewritten by tools (JSON, images, lock files, `VERSION`, `migrations/`, `config/bundles.php`/`reference.php`, `importmap.php`, `bin/`, `public/`, `.claude/`) are annotated in `REUSE.toml`. Third-party content keeps its own copyright holder.
