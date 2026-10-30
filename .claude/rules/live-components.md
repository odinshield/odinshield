---
paths:
  - "src/Twig/Components/**/*.php"
  - "templates/components/**"
---

# Symfony UX Live Components

Two live components exist in `src/Twig/Components/`. Both are gated at the class level because the `/live/component/…` endpoint is separate from the normal controller stack — without a gate any authenticated user could POST to it (SEC-006). The two gates are **not** identical:

- **`SoftwareForm`** — inline create/edit for `Software` entities. Class-level `#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]` (not FULLY — it must match `SoftwareController`, or a remember-me session breaks on re-render, #186); `save()` then enforces the per-operation voter itself — `SoftwareVoter::CREATE` for new, `SoftwareVoter::MODIFY` for edit (both bypassed for ROLE_ISB / ROLE_SOFTWARE_OWNER, edit additionally allows the Software-Responsible). Uses `ComponentWithFormTrait` + `DefaultActionTrait`. Accepts `#[LiveProp] returnParams` so the redirect goes back to the correct filtered list. Listens for `vendor:created` from `VendorQuickCreateForm` to pre-select the new vendor.
- **`VendorQuickCreateForm`** — modal quick-create for `Vendor` entities, embedded inside `SoftwareForm`. Class-level `#[IsGranted(new Expression("is_granted('ROLE_SOFTWARE_OWNER') or is_granted('ROLE_ISB')"))]` (mirrors `SoftwareVoter::MODIFY_BYPASS`; `role_hierarchy` is intentionally absent, so ISB is listed explicitly). On save, emits `vendor:created` (caught by `SoftwareForm::onVendorCreated`), dispatches `modal:close` and a `toast:spawn` browser event (client-side success toast — see `.claude/rules/hotwire-turbo.md`). Uses `#[PostMount(priority: 10)]` to initialise `initialFormData` before `ComponentWithFormTrait` calls `initializeForm()`.

Template path for anonymous Twig components is `templates/components/` (configured in `config/packages/twig_component.yaml`). Named components resolve to `App\Twig\Components\<Name>`.
