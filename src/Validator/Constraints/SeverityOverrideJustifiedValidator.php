<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use App\Entity\Vulnerability;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class SeverityOverrideJustifiedValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SeverityOverrideJustified) {
            throw new UnexpectedTypeException($constraint, SeverityOverrideJustified::class);
        }

        if (!$value instanceof Vulnerability) {
            throw new UnexpectedTypeException($value, Vulnerability::class);
        }

        $source = $value->getSourceSeverity();
        if (null === $source || $value->getSeverity() === $source || $value->isSeverityOverridden()) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('severityOverrideReason')
            ->addViolation();
    }
}
