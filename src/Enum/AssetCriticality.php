<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Business criticality of an asset group — BSI-CS 093 §3.1 "Wichtigkeit".
 * Feeds into the effective-risk score alongside severity, exposure, data sensitivity.
 */
enum AssetCriticality: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low => 'secondary',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
