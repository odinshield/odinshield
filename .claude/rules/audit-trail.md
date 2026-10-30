---
paths:
  - "src/Entity/**/*.php"
---

# Audit trail

All core entities carry `#[Auditable]` from `dh/auditor`: `User`, `Vulnerability`, `VulnerabilityOccurrence`, `Software`, `Vendor`, `AssetGroup`, `AssetType`, `UserGroup`. Audit tables follow the `<entity_table>_audit` naming convention. The columns `id`, `uuid`, `createdAt`, `updatedAt`, `createdBy`, `updatedBy` are globally ignored (configured in `config/packages/dh_auditor.yaml`). Individual fields can be excluded per-entity with `#[Ignore]`.

Read audit history in Twig via the `audit_entries(entity, page, pageSize)` function provided by `AuditExtension` — returns `{entries: Entry[], total: int}`. The `AuditController` renders paginated history for any entity implementing `HasIdInterface`.

**Object links follow view rights (#201):** `AuditLabelResolver` names every object but sets a route — and therefore a link — only when the current viewer may open the target page. Per-object types go through their single resolver in one batch (vulnerability/occurrence via `VulnerabilityAccess::applyViewFilter()`, software/asset group via their voters); class-gated pages use the controller's `ACCESS` constant (`AssetTypeController`, `VendorController`) or `ROLE_ADMIN`. A new audited entity type needs an entry in `ROUTES` **and** in `viewableIds()`, or its objects are never linked. Without a logged-in user (CSV export stream) nothing is linkable, which the export does not need.
