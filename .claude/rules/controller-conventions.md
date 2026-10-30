---
paths:
  - "src/Controller/**/*.php"
---

# Controller conventions

**UUID routing:** All entities use UUIDv7 via `HasUuid`. Routes use `{uuid}`, resolved with `#[MapEntity(mapping: ['uuid' => 'uuid'])]` — Symfony's auto-resolver does not work for non-`id` parameters.

```php
#[Route('/{uuid}', name: 'app_user_show', methods: ['GET'])]
public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] User $user): Response
```

**Pagination:** Use `Doctrine\ORM\Tools\Pagination\Paginator` directly. Controller reads `search`, `page`, `limit`, `sort`, `direction` from query params; calculates `from`/`to` in PHP (not Twig, since Twig has no `min` filter). Repository-side details: see `.claude/rules/repository-conventions.md`.

**Controller list params:** CRUD controllers that link back to a list view pass filter state (`search`, `page`, `limit`, `sort`, `direction`, `filter`) through redirects. Validate all params against allowed values via a `resolveListParams()` method — never pass raw user input unvalidated.

**Return target after edit (#211):** Edit links on a detail page carry `return_to=show` (merged with the list params); `edit()` reads it as `'show' === $request->query->getString('return_to')` — only that fixed value, never a URL — and redirects to `app_<entity>_show` with the list params, else to the list as before. The edit page's header back link and Cancel follow the same flag (`return_to_show` in the template, `returnToShow` LiveProp in `SoftwareForm`). Where visibility is per record (Vulnerability, Software, AssetGroup) redirect to the detail page only if the `…Voter::VIEW` check still passes after saving, otherwise to the list — never into a 403. After **create**, only Vulnerability opens its detail page (same VIEW guard); all other entities return to the list. Covered by `tests/Functional/EditReturnTargetTest.php`.
