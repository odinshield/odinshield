<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #51: an asset group may only be newly attached to a vulnerability when the
 * inventory backs it — i.e. when at least one of the linked software products runs
 * in that group (`AssetGroup.software`).
 *
 * Two modes, driven implicitly by whether software is linked at all:
 *  - software linked  → the selectable asset groups are a *subset* of the union
 *                       derived from that software. The inventory is the truth.
 *  - no software      → free choice. Nothing is claimed about the inventory, so
 *                       nothing can contradict it. This is the deliberate path for
 *                       findings that cannot be inventoried — a Linux library, a
 *                       pentest finding on an unlisted component.
 *
 * Grandfathering: only occurrences that are not yet persisted are checked. A finding
 * recorded while the inventory link existed stays valid after that link is removed —
 * it carries status, remediation date and audit history, and someone editing an
 * unrelated field must not be forced to delete it to get past validation.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AssetGroupsCoveredBySoftware extends Constraint
{
    public string $notCoveredMessage = 'vulnerability.asset_groups.not_covered_by_software';
    public string $softwareWithoutAssetGroupsMessage = 'vulnerability.software.no_asset_groups';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
