<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Lifecycle action applied to the predecessor `Software` row when a successor
 * is created. See PLAN §3 step 4.
 */
enum PredecessorAction: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Eol = 'eol';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Keep active',
            self::Inactive => 'Mark inactive',
            self::Eol => 'Mark EOL',
        };
    }
}
