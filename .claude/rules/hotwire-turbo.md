---
paths:
  - "templates/**"
  - "assets/controllers/**/*.js"
---

# Hotwire / Turbo

**Turbo Stream responses:** Controllers handling a POST from a Turbo-driven form branch on `TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()` and return a `<turbo-stream>` response to mutate the page in place (preferred over a 303 when the user isn't navigating). Always keep the redirect path as the non-Turbo fallback — functional tests submit without the `Accept: text/vnd.turbo-stream.html` header and must still work. Do NOT `addFlash()` on the stream branch; emit toasts directly via the stream. Reference: `VulnerabilityController::updateOccurrenceStatus`.

**Turbo side-effects sink:** `base.html.twig` hosts `<div id="turbo-side-effects">`, the target for `<turbo-stream action="append">` payloads that spawn short-lived, self-removing Stimulus controllers (`toast-spawn`, `modal-close`) alongside a row replace. The sink node keeps these effects independent of the actual content being updated.

**Dedicated modals for entity-scoped actions:** Any feature whose modal body is server-rendered AND may be invoked from multiple pages (especially inside another modal) uses its own dedicated modal hosted once in `base.html.twig` via `<body data-controller="...">`, not the generic `remote-modal`. The generic `remote-modal` is reserved for full entity preview/detail modals on index pages. Reference: `occurrenceStatusModal` + `occurrence-status-modal` controller.

**Bootstrap Modal instance reuse:** Never call `new Modal(el).show()` on an element that may already have an instance attached — doing so orphans the previous instance's backdrop, which `Modal.hide()` on the new instance cannot clean up. Always `Modal.getInstance(el) || new Modal(el)` when opening.

**Nested modal close:** Bootstrap's `Modal.hide()` unconditionally removes `body.modal-open` even if another modal is still visible. When closing an inner modal while an outer remains, listen for `hidden.bs.modal` and re-apply `body.modal-open` if any `.modal.show` still exists — otherwise the page scrolls behind the remaining modal. Reference: `modal_close_controller.js`.

**Stimulus action scope:** `data-action="controller#method"` routes the event to the nearest ancestor with matching `data-controller`. Controllers reachable from server-rendered content injected anywhere (e.g., modal bodies fetched via `fetch()`) must be mounted on `<body>` or an equally broad container — not inside a per-page wrapper.

**Shared row partials for Turbo targets:** When the same `<tr>` is rendered in more than one view (edit card, show tab, nested detail modal) AND is the target of `<turbo-stream action="replace" target="…">`, extract it to a single partial with a stable `id="…-{uuid}"`. One source of truth keeps the stream response working identically in every context. Reference: `templates/vulnerability/_occurrence_row.html.twig`.

**In-place stream staleness (accepted, L19):** Row-level `<turbo-stream action="replace">` responses (bulk-close, occurrence-status) update only the targeted rows plus the side-effects sink. They do **not** remove rows that no longer match the active filter, nor re-sync the `"X–Y von Z"` pagination footer or the navbar bell count — those reconcile on the next full navigation (any filter/sort/pagination click). This is intentional: re-rendering the whole `vulnerability-table` frame on every action would defeat the point of an in-place stream. Don't "fix" it by reloading the frame.

**Toast from a browser event (`toast:spawn`):** Code with no navigation (e.g. a Live Component action like `VendorQuickCreateForm`) can raise a client-side toast by dispatching a bubbling `toast:spawn` CustomEvent (`{level, message, closeLabel}`) — the `toast-listener` controller on `<body>` catches it and calls the shared `spawnToast()` helper (`assets/toast.js`). This is the no-navigation analogue of the flash→toast and `toast-spawn` stream paths.

**Flash → Toast:** Flash messages (`success`, `error`, `warning`, `info`) are rendered in `base.html.twig` using a Stimulus `toast` controller (`assets/controllers/toast_controller.js`) that calls `new Toast(element).show()` from `@tabler/core`. Use `{% set all_flashes = app.flashes %}` before iterating — Twig variable assignment inside `for` loops does not affect outer scope. On Turbo-stream responses use `toast-spawn` instead — flashes would replay on the next real navigation.

**Turbo 8 prefetch is on by default (escape hatch):** Since the 7.3.0 → 8.0.23 bump (issue #45), Turbo prefetches a link's target on **hover** — a GET fires with `sec-purpose: prefetch` before any click. Verified safe here because no GET-only controller action writes (no `flush()`/`persist()`/`getOrCreate()`), so a spurious prefetch cannot mutate state; keep it that way when adding GET routes. The cost is load, not correctness: hovering the "Schwachstellen" link fires the full paginated `GROUP BY v.id` + `MAX(effectiveRiskScore)` query. **If prefetch ever becomes a problem** (DB load, log noise, a GET that must not fire unbidden), disable it globally with a single meta tag in `base.html.twig`:

```twig
<meta name="turbo-prefetch" content="false">
```

Deliberately not set today — the navigation win is real and nothing breaks.

**Log noise from prefetch is handled, not silenced (#77):** A prefetch that lands on a forbidden
route produces a correct 401/403 that nobody clicked and nobody sees — Turbo discards a failed
prefetch without a trace, so the operator would get a mail naming a URL the user never visited
(real triggers: session switched in the same tab, role changed mid-session, back button onto a
cached page). `App\Monolog\PrefetchProcessor` marks such records via the `Sec-Purpose` header and
`ScannerNoiseActivationStrategy` drops **only** 401/403 that carry that mark — a real navigation
still alerts, and a 500 during a prefetch still alerts. The mark has to be stamped onto the record
while the request is on the stack: the strategy must never read `RequestStack`, which under
FrankenPHP worker mode is not reliably populated when a record is evaluated (that is the bug that
made the stock `excluded_http_codes` leak 404s to the mailer in the first place).

**Turbo 8 morphing is NOT active:** Turbo 8 reads the page-refresh strategy from `<meta name="turbo-refresh-method">`. We do not set it, so refreshes stay on Turbo 7's `replace` behaviour and no morphing runs. Only opt in deliberately — morphing would change how the L19 in-place-stream staleness above behaves.
