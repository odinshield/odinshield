<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Sensitivity of data processed by an asset group — BSI-CS 093 §3.1 "Schützenswerte Daten".
 */
enum DataSensitivity: string
{
    case Restricted = 'restricted';
    case Confidential = 'confidential';
    case Internal = 'internal';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Restricted => 'Restricted',
            self::Confidential => 'Confidential',
            self::Internal => 'Internal',
            self::Public => 'Public',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Restricted => 'danger',
            self::Confidential => 'warning',
            self::Internal => 'info',
            self::Public => 'secondary',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Restricted => 4,
            self::Confidential => 3,
            self::Internal => 2,
            self::Public => 1,
        };
    }
}
