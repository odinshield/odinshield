---
paths:
  - "assets/**/*.js"
---

# JavaScript Coding Standards

Conventions for JavaScript under `assets/` (AssetMapper, **no** build step).
Mechanically checked via Biome (`make asset-lint`) — this rule adds what Biome
does not know (project/Stimulus/Tabler specifics).

- **ES6+ modules** via AssetMapper, **no** bundler/Node build. Imports go through the
  importmap — no relative `node_modules` paths.
- **4-space indent.**
- **Stimulus controllers** live in `assets/controllers/`, filename `*_controller.js`,
  default-export a class `extends Controller`.
- Import Bootstrap components from **`@tabler/core`** (e.g. `Toast`, `Modal`),
  not from `bootstrap` directly.
- `/* stimulusFetch: 'lazy' */` enables lazy loading — **omit** for controllers needed
  on initial page load.

## Stimulus / Bootstrap pitfalls

- **Modal instance reuse:** never `new Modal(el).show()` on an element that already has an
  instance attached — it orphans the backdrop. Always `Modal.getInstance(el) || new Modal(el)`.
- **Nested modal close:** Bootstrap's `Modal.hide()` removes `body.modal-open` even when
  another modal is still open. When closing an inner modal, listen for `hidden.bs.modal`
  and re-apply `body.modal-open` if a `.modal.show` still exists
  (reference: `modal_close_controller.js`).
- **Action scope:** `data-action="controller#method"` targets the nearest ancestor with a
  matching `data-controller`. Controllers that must be reachable from server-rendered markup
  (e.g. modal bodies fetched via `fetch()`) must be mounted broadly on `<body>` — not in a
  per-page wrapper.

## Symfony UX version & the toolkit-kit-linter (decision, issue #16)

Symfony UX is pinned at **3.3** (`symfony/ux-chartjs`, `ux-live-component`, `ux-turbo`,
`ux-twig-component`, `stimulus-bundle` — `^3.3`). Their JS ships from `./vendor/**/assets/dist/`
(path-based in `importmap.php`), so a `composer update` moves the JS with the PHP package. The
**versioned** importmap pins are independent and deliberately frozen — notably `@hotwired/turbo`
**7.3.0** and `@hotwired/stimulus` 3.2.2. Do not let a UX bump raise them; verify `importmap.php`
is unchanged after `composer update`.

Turbo staying at 7.3.0 is **not** a `ux-turbo` compatibility constraint — that was an earlier
misreading. `ux-turbo`'s `assets/package.json` declares `"@hotwired/turbo": "^7.1.0 || ^8.0"`, so
Turbo 8 is accepted. What remains open is Turbo 8's own behaviour (morphing / view transitions /
InstantClick — unclear from the release notes which are default vs. opt-in). Tracked in issue #45.

The UX 3.2 blog advertises a **toolkit-kit-linter** (`vendor/bin/ux-toolkit-kit-lint`). It is
**not adopted**: it targets maintainers of distributable UX-Toolkit *kits* (`symfony/ux-toolkit`,
which we do not install or build) and expects a kit manifest/`copy-files` structure — it cannot be
pointed at our plain `assets/controllers/`.

Its one conceptually-useful check for us — a Twig `data-controller="x"` / `data-action="…x#m"` with
no `x_controller.js` (a silent no-op Stimulus just ignores, not caught by Biome/`make asset-lint`) —
is instead implemented as a tiny host-side script: **`make lint-stimulus`** (`tools/lint-stimulus.sh`),
wired into `make verify` (and so `make ci`). It scans `data-controller` / `data-action` (both HTML
attributes and Twig `form_widget` attr hashes) plus `stimulus_controller('…')`, maps each kebab name
to `assets/controllers/<snake>_controller.js`, and fails if a file is missing. Third-party controllers
are skipped: `live` (ux-live-component) and any namespaced `…--…` identifier.
