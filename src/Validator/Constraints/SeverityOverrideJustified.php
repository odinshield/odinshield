<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * #50: an imported vulnerability whose severity differs from the source value needs a reason.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class SeverityOverrideJustified extends Constraint
{
    public string $message = 'vulnerability.severity_override_reason.required';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
