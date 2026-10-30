<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * How a software product's vulnerabilities are maintained — BSI-CS 093 §5.2.
 *
 * - Import: maintained automatically via the CSAF importer (only these are touched by the importer)
 * - Manual: maintained by the responsible users; drives the review-overdue dashboard widget
 * - None: not maintained at all (deliberate operator decision)
 */
enum MaintenanceSource: string
{
    case Import = 'import';
    case Manual = 'manual';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Import',
            self::Manual => 'Manual',
            self::None => 'None',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Import => 'success',
            self::Manual => 'warning',
            self::None => 'danger',
        };
    }
}
