---
name: quality-gate
description: Runs the project's make-based quality checks after code changes and only declares work done when the relevant checks pass. Use whenever PHP, Twig, JS, CSS, YAML, translation, Markdown or shell files under this project were written, edited or refactored, or when asked to verify/check changes before finishing.
---

# Quality Gate

Three levels, each at its own point in the workflow. Nothing runs twice on purpose:
`verify` is a subset of `ci`, and `push`/`ship`/`build-release` only compare the
`.ci-ok` stamp with HEAD — they never check anything themselves.

| When | What | Purpose |
|---|---|---|
| After each change | matching fixer + checker + test subset (table below) | fast feedback, seconds |
| Before each commit | `make fix` | formatting lands in the commit itself, no "fix style" commit later |
| After the last commit of a work package | `make ci` | full gate; stamps HEAD into `.ci-ok` for `make push` |

`make release` runs `make ci` itself — the release commit is a new commit and needs its own stamp.

## 1. After each change — only what matches the touched files

| Changed | Auto-fixer | Checker |
|---|---|---|
| PHP (`src/`, `tests/`) | `make fix-php` | `make stan` |
| Twig (`templates/`) | — | `make twig-lint`; new `data-controller`/`data-action` → `make lint-stimulus` |
| Translations (`translations/`) | — | `make trans-lint` |
| YAML under `config/` | — | `make lint-yaml`, then `make cc` |
| JS/CSS (`assets/`) | `make asset-fix` | `make asset-lint`; new Stimulus controller → `make lint-stimulus` |
| Markdown | `make md-fix` | `make md-lint` |
| Shell scripts, `.githooks/pre-push` | `make shell-fix` | `make shell-lint` |
| `Dockerfile` | — | `make docker-lint` |

Order: auto-fixers first (they modify files; keep their changes), then checkers, then tests.

**Tests during iteration** — the matching subset, not the full suite:
- a single file: `bin/phpunit tests/path/to/FooTest.php` (in the claude container PHP runs directly)
- `make test-unit` after pure logic/service changes
- `make test-functional` for controller/request-flow changes
- `make test-coverage` only on explicit request

Tests need the stack's database. If it is unreachable, print a hint (`make start` on the host,
`make test-db` for a fresh volume) instead of guessing.

## 2. Before each commit

`make fix` — every auto-fixer in one go. No full check here: commits are local and cheap to
amend, and the stamp only counts on a clean tree anyway.

## 3. After the last commit — `make ci`

`make ci` stamps only on a **clean working tree**, so it runs *after* the commit, never before.
It records HEAD at its start and stamps only if HEAD is unchanged and the tree clean at the end —
so do not commit or edit while it runs (`HEAD moved during ci — no stamp`).
A branch that differs from `main/main` only in `.claude/memory/` and `MEMORY.md` gets
`secrets-lint` + `reuse-lint` instead of the full run (`git fetch main` first, or a stale
`main/main` makes it a full run).
Start it in the background (`make ci > var/log/ci.log 2>&1`) and wait for the completion
notification. On success read only the last line; on failure read only the failing step's
section and quote it verbatim. Red → fix, commit, run `make ci` again.

Never make `ci` auto-fix: a gate that modifies files would stamp a state it never checked.

## On failure

Summarise the output, name the cause, fix it, re-run the affected check — until clean.

Name the cause from a measurement, not from reading the source. The claude container has no
Docker socket, so no `docker compose exec` — everything below works from here:
- A 500 in the dev app leaves a profiler entry, served by php over HTTP at `http://php`.
  Every response names its own in `X-Debug-Token-Link` (`curl -sI http://php/<path>`).
  - latest request: `http://php/_profiler/latest?panel=exception` (or `panel=logger`, `db`,
    `request`, `router`)
  - the last requests: `http://php/_profiler/empty/search/results?limit=10`, then
    `http://php/_profiler/<token>?panel=…`
  - read with `curl -s …` (HTML — `grep` it) or the `dev-browser` skill.
- For behaviour across several requests: `tail -n 200 var/log/dev.log` (or `grep` it) instead
  of guessing which code path ran. `var/log` is the `dev_log` volume shared with php and worker,
  so it holds the web app's and the worker's log next to this container's console output.
  Failing tests log to `var/log/test.log` (errors only, with the buffered context).
- For "which service is actually wired here": `bin/console debug:container <id>` and
  `debug:autowiring`, `debug:router` for routes, `debug:event-dispatcher` for listener order —
  same code and env as php, run directly.

## Completion

Report "change done" only when all relevant level-1 checks are green. Report "ready to push"
only after `make ci` stamped the current HEAD.
