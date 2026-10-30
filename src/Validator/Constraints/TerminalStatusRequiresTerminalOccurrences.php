<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class TerminalStatusRequiresTerminalOccurrences extends Constraint
{
    public string $message = 'vulnerability.status.occurrences_not_terminal';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
