---
paths:
  - "src/EventListener/**/*.php"
  - "src/Entity/**/*.php"
---

# Doctrine listeners

**Denormalisation cascade across entities:** When a field is denormalised (e.g. `VulnerabilityOccurrence.effectiveRiskScore` computed from parent `AssetGroup` and `Vulnerability`), a plain `#[AsDoctrineListener(Events::onFlush)]` pattern does NOT pick up inverse-side collections — `$assetGroup->getVulnerabilityOccurrences()` is often empty at flush time because the inverse side isn't populated in memory. Two working patterns: (a) for the **owning-side change** (occurrence insert/update), `recomputeEffectiveRiskScore()` on the entity plus `recomputeSingleEntityChangeSet()` lets Doctrine pick the new value up in the current flush; (b) for **cross-entity cascade** (parent field changes), query the repository directly in `onFlush` (`findBy(['assetGroup' => $entity])`), cache the new scores into `$this->pendingUpdates`, then issue raw `UPDATE` SQL in `postFlush`. The parent change is audit-logged; the derived score change on occurrences is a pure denormalisation and sidesteps UoW intentionally. Reference: `EffectiveRiskListener`.

**`HasTimestamps` requires `#[ORM\HasLifecycleCallbacks]`:** The trait uses `#[ORM\PrePersist]` / `#[ORM\PreUpdate]`, which are only invoked when the entity class itself declares `#[ORM\HasLifecycleCallbacks]`. Omit it and `createdAt`/`updatedAt` are never set. `HasEditedBy` is populated by `EditedByListener` (Doctrine `prePersist`/`onFlush`) — no annotation needed for that trait.
