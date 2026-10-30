<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\UpdateCheck;

/**
 * #223: what the dashboard shows ISB and admin when a newer release is published.
 */
final readonly class AvailableUpdate
{
    public function __construct(
        public string $version,
        public string $currentVersion,
        public ?string $url,
        public bool $security,
        public ?\DateTimeImmutable $releasedAt,
    ) {
    }
}
