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
 * One advisory product reported against one inventory entry, with the reason (#134).
 */
final readonly class InventoryMatch
{
    public function __construct(
        public Software $software,
        public CsafProduct $product,
        public MatchReason $reason,
    ) {
    }
}
