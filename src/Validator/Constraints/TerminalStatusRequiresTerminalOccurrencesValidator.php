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

class TerminalStatusRequiresTerminalOccurrencesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TerminalStatusRequiresTerminalOccurrences) {
            throw new UnexpectedTypeException($constraint, TerminalStatusRequiresTerminalOccurrences::class);
        }

        if (!$value instanceof Vulnerability) {
            throw new UnexpectedTypeException($value, Vulnerability::class);
        }

        // Precondition only applies when moving the vulnerability to a terminal status.
        if (!$value->getStatus()->isTerminal()) {
            return;
        }

        $openCount = 0;
        foreach ($value->getOccurrences() as $occurrence) {
            if (!$occurrence->getStatus()->isTerminal()) {
                ++$openCount;
            }
        }

        // No occurrences (or all terminal) → nothing blocks the transition.
        if (0 === $openCount) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('%count%', (string) $openCount)
            ->setPlural($openCount)
            ->atPath('status')
            ->addViolation();
    }
}
