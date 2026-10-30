---
paths:
  - "src/Validator/**/*.php"
---

# Validator conventions

**Validator translation domain:** Symfony's validator component translates error messages through the `validators` domain, not `messages`. Put constraint message keys in `translations/validators.{de,en}.yaml` — `messages.*.yaml` will silently fall through to the raw key. Reference: `ResponsibleUserAligned` constraint + `translations/validators.en.yaml`.

**Password policy (#239):** Configured in `config/packages/password_policy.yaml` as `%app.password_policy%`, after the BSI recommendation: a password passes with **shape 1** (≥ `min_length` 8 with every `require_*` class) **or shape 2** (passphrase: ≥ `passphrase_min_length` 20 with `passphrase_min_classes` 2 different classes; `0` switches it off). Classes: Unicode upper/lower case (Ä, ß count), ASCII digits, and "special" = any printable ASCII punctuation **or the space**; non-ASCII symbols are allowed but count for nothing. The rules live **only** in `App\Security\PasswordPolicy` — `PasswordComplexityValidator`, the console (`Utils\Validator`, English messages) and every password field hint (`PasswordPolicy::hint()`, passed as `%policy%` via `help_translation_parameters`) ask it; never re-implement a rule elsewhere. Passwords are never trimmed (`PasswordType` `trim: false`, CLI prompt `setTrimmable(false)`) — spaces are part of the policy now. Changes require `cache:clear`.
