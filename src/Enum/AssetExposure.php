<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Network exposure of an asset group — BSI-CS 093 §3.1.
 * Internet-facing systems amplify every vulnerability's impact.
 */
enum AssetExposure: string
{
    case Internet = 'internet';
    case Intranet = 'intranet';
    case Isolated = 'isolated';

    public function label(): string
    {
        return match ($this) {
            self::Internet => 'Internet-facing',
            self::Intranet => 'Intranet',
            self::Isolated => 'Isolated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Internet => 'danger',
            self::Intranet => 'warning',
            self::Isolated => 'success',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Internet => 3,
            self::Intranet => 2,
            self::Isolated => 1,
        };
    }
}
