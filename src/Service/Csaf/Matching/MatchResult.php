<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

use App\Entity\Software;
use App\Service\Csaf\Dto\CsafProduct;

/**
 * Outcome of matching one advisory's products against the inventory (#134).
 */
final readonly class MatchResult
{
    /**
     * @param list<InventoryMatch>                                        $hits           one per software, first matching product wins
     * @param list<array{product: CsafProduct, software: Software}>       $versionOutside key matched, version did not — counted, never reported
     * @param list<array{product: CsafProduct, software: Software}>       $nearMisses     one token equal or contained, the other only contained — logged as alias candidates
     */
    public function __construct(
        public array $hits = [],
        public array $versionOutside = [],
        public array $nearMisses = [],
    ) {
    }

    /**
     * @return list<Software>
     */
    public function software(): array
    {
        return array_map(static fn (InventoryMatch $m) => $m->software, $this->hits);
    }
}
