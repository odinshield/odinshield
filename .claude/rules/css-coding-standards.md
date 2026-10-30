---
paths:
  - "assets/**/*.css"
---

# CSS Coding Standards

Conventions for CSS under `assets/`. Mechanically checked via Biome
(`make asset-lint`) — this rule adds the project/Tabler specifics.

- **Standard CSS only** — no SCSS/LESS, **no** nested rules.
- **`kebab-case`** class names. Own classes must not start with `table-`, `bg-`, `text-bg-`,
  `link-`, `container` or contain `col-`/`navbar-expand`: Tabler 1.6 matches these by attribute
  selector (e.g. `.table-striped` skips such cells — `table-col-*` broke the list's zebra stripes).
- Use **Tabler 1.6 / Bootstrap 5.3** utilities; theme via **`--tblr-*`** variables;
  dark mode via `[data-bs-theme='dark']`.
- Use **logical properties** (`margin-block-end`, **not** `margin-bottom`).
- Breakpoints only **`md`/`lg`/`xl`**.
