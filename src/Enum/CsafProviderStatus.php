<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Operational state of a CSAF provider in the import pipeline.
 *
 * - Enabled: ops-enabled, eligible for the next import cycle.
 * - AutoPaused: circuit breaker tripped after consecutive failures. Resumable via
 *   `odinshield:csaf:resume`; runner ignores until then.
 * - Disabled: ops-disabled. Runner ignores; not automatically recoverable.
 */
enum CsafProviderStatus: string
{
    case Enabled = 'enabled';
    case AutoPaused = 'auto_paused';
    case Disabled = 'disabled';

    public function isRunnable(): bool
    {
        return self::Enabled === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Enabled => 'Enabled',
            self::AutoPaused => 'Auto-paused',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Enabled => 'success',
            self::AutoPaused => 'warning',
            self::Disabled => 'secondary',
        };
    }
}
