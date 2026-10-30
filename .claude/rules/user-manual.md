---
paths:
  - "docs/manual/**"
  - "src/Controller/ManualController.php"
  - "src/Controller/PagefindAssetController.php"
  - "src/Markdown/**/*.php"
---

# User manual (Diátaxis + Pagefind)

The in-app user manual is German Markdown under `docs/manual/de/` (English in `docs/manual/en/`), rendered to HTML at request time by `ManualController` (`/manual`, `/manual/{slug}`) via the `app.markdown.manual_converter` service (`ManualMarkdownConverterFactory`, raw HTML allowed — sources are trusted, local files). Slugs map through a `ROUTE_MAP` in the controller, not directly to filenames. Manual chapters are versioned independently of the app via `MANUAL_VERSION` (canonical in the `Makefile`, mirrored into `environment/.env`, fallback in `config/services.yaml`; `VersionConsistencyTest` guards drift).

Search is **Pagefind**, a static index built offline: `make manual-index` runs `odinshield:manual:render-static` (writes HTML to `.pagefind-build/manual/`, refuses to run in `APP_ENV=prod` without `--force`) then the `pagefind` binary emits the index into `public/pagefind/`. `PagefindAssetController` serves those files with path-traversal guarding. The index is built into the prod image — do not expect it to regenerate at runtime. New/edited chapters should be reviewed with the `manual-qa-diataxis` agent for Diátaxis compliance.
