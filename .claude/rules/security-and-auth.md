---
paths:
  - "src/Security/**/*.php"
  - "src/EventSubscriber/**/*.php"
  - "src/EventListener/**/*.php"
---

# Security & authentication

**Symfony 7+ voter signature:** `Voter::voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool` — the trailing `?Vote $vote = null` parameter is required; omit it and PHPStan + runtime both complain. PHPDoc uses `@extends Voter<string, Vulnerability|null>` (attribute type is `string`, not `self::*`, so the `*_VIEW`/`*_MODIFY`/`*_DELETE` constants are accepted).

**Authentication flow:** `UserChecker` (pre-auth) blocks disabled accounts via `CustomUserMessageAccountStatusException` — not `DisabledException`, which Symfony masks as "Bad credentials". The checker never runs for an existing session: `User::isEqualTo()` (SEC-015, #184) ends a session whose reloaded account is inactive or has a changed password/roles/username, and `DeactivatedUserListener` puts the "account disabled" message on the login page. Any new field that must end a live session belongs in `isEqualTo()`. Flip side: the token's user **is** the managed entity, so a form that edits the *own* `User` and then does not flush (rejected, invalid) must `refresh()` it — otherwise the session stores the unsaved roles/username and the next request signs the user out (`UserController::edit`, #187). `MustChangePasswordSubscriber` intercepts `kernel.request` and redirects users with `mustChangePassword=true` to `/change-password` (allowed routes: `app_change_password`, `app_login`, `app_logout`, and `/_*` system paths). After password change, user is logged out.

**ROLE_ADMIN is all-powerful by decision (SEC-013, #196):** roles are orthogonal (no `role_hierarchy`, nothing inherited), but that is not separation of duties. An admin manages every account's roles including their own — granting themselves `ROLE_ISB` bypasses the 4-eyes gate for close/delete — and `/audit` + CSV export show all vulnerability content unfiltered although `ROLE_ADMIN` is not a view-bypass role. Accepted because `roles` changes are audited; the only technical guard is SEC-015 (no self-deactivation / self-de-admin). Don't add self-assignment blocks or filter `/audit` without reopening the decision.

**CSP constraints** (defined in `SecurityHeadersListener`):

- `unsafe-inline` in `script-src` is mandatory: AssetMapper emits an inline `<script type="importmap">` — no CDN workaround exists.
- `data:` in `script-src` is mandatory **in production only**: `asset-map:compile` replaces CSS imports with `data:application/javascript,…` ES module stubs.
- `unsafe-inline` in `style-src` is mandatory: Tabler/Bootstrap inject inline styles via JS.
- Do not tighten the CSP without testing the production asset-compiled build — dev is unaffected and will not catch regressions.
