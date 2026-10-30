<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

/**
 * Validated filter state for the global audit timeline (issue #32). All fields are optional;
 * a null field means "no restriction". Built by AuditController::resolveListParams() from
 * already-validated request params, so values here are trusted.
 */
final readonly class AuditTimelineFilter
{
    public function __construct(
        public ?string $action = null,
        public ?string $entityType = null,
        public ?string $user = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
    ) {
    }
}
