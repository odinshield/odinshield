---
paths:
  - "src/Repository/**/*.php"
  - "src/Security/**/*.php"
---

# Repository & Access-Control Conventions

Project-specific conventions for Doctrine repositories and the tightly coupled
vulnerability access logic. (Generic PHP style is enforced separately via
`@Symfony`/`@PSR12` in `php-cs-fixer.php` + PHPStan — not here.)

## Repositories

- `src/Repository/` holds QueryBuilder/DQL logic; paginate with Doctrine's
  `Doctrine\ORM\Tools\Pagination\Paginator` **directly** (not KnpPaginatorBundle).
- **Pagination pattern:** the controller reads `search`, `page`, `limit`, `sort`, `direction`
  from query params; `from`/`to` are computed in **PHP** (not Twig — Twig has no `min` filter).

## Access control (single resolver)

- **Single-resolver pattern:** `src/Security/VulnerabilityAccess.php` is the **single** source
  of truth for view/modify/delete rules. Both `VulnerabilityVoter` (single-entity checks via
  `denyAccessUnlessGranted(VulnerabilityVoter::VIEW|MODIFY|DELETE, …)`) and
  `VulnerabilityRepository::findPaginated()` / the dashboard count methods go through it. This
  keeps the list filter and the per-entity gate in lock-step — never duplicate the rules in a
  controller or a template.
- **DQL predicate shape:** `applyViewFilter(QueryBuilder, User, alias)` is a no-op for
  view-bypass roles (`ROLE_ISB`, `ROLE_SOFTWARE_OWNER`, `ROLE_HARDWARE_OWNER` —
  `VulnerabilityAccess::VIEW_BYPASS_ROLES`). For everyone else it appends an OR-chain of
  `EXISTS` subqueries — responsibleUser on vulnerability, software, or asset group, plus
  UserGroup membership via `AssetType`. Uses `EXISTS` rather than `JOIN … DISTINCT` specifically
  to leave the outer query's `GROUP BY v.id` + `MAX(occ_sort.effectiveRiskScore)` ordering
  untouched.
- **UserGroup scope:** UserGroups link to `AssetType` (M:N), **not** directly to `AssetGroup`.
  Access chain: `User → UserGroup → AssetType → AssetGroup → VulnerabilityOccurrence →
  Vulnerability`. UserGroups are a pure vulnerability-scoping mechanism; AssetGroup / Software
  CRUD stays role-gated (`ROLE_HARDWARE_OWNER` / `ROLE_SOFTWARE_OWNER`).

## Dashboard counts (deviation to note)

The dashboard count methods (`countInActiveStatuses()`, `countHighSeverityInActiveStatuses()`,
`countInProgress()`) filter **strictly** on `v.responsibleUser = :assignee` when a `?User $assignee`
is passed — **not** through `VulnerabilityAccess::applyViewFilter()` (intentional: the broader
OR-chain does not match "assigned to you"). Full rationale + UI behaviour: see the "Dashboard
vulnerability counts" section in `CLAUDE.md`.
