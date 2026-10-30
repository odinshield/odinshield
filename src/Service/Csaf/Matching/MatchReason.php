<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

/**
 * Why an advisory product was reported against an inventory entry (#134, D1). Persisted in the
 * "Matched because" block of the source description so an ISB can see how certain a hit is
 * without a separate review tier.
 */
enum MatchReason: string
{
    /** The advisory names exactly the inventory version, segment for segment. */
    case Exact = 'exact';

    /** The inventory's major/minor prefix overlaps the advisory's range — the usual D1 case. */
    case Prefix = 'prefix';

    /** The inventory version has no numeric start; every advisory for the product is reported. */
    case InventoryUnparseable = 'inventory-unparseable';

    /** The advisory's version text could not be read; reported for all versions (fail-open, D5). */
    case AdvisoryUnparseable = 'advisory-unparseable';

    /**
     * English on purpose — importer-written text is single-language, independent of the UI.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Exact => 'the advisory names exactly this version',
            self::Prefix => 'the inventory version is kept at major/minor level and overlaps the advisory range — check whether your patch level is already past it',
            self::InventoryUnparseable => 'the inventory version has no numeric part, so every version is treated as affected',
            self::AdvisoryUnparseable => 'the advisory version text could not be read, so it is treated as affecting all versions',
        };
    }
}
