<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use App\Security\PasswordPolicy;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class PasswordComplexityValidator extends ConstraintValidator
{
    public function __construct(
        private readonly PasswordPolicy $policy,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordComplexity) {
            throw new UnexpectedTypeException($constraint, PasswordComplexity::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // #239: the rules live in PasswordPolicy (BSI shape 1 or 2), shared with the console.
        // L25: every unmet rule is its own violation, so one submission shows the full list.
        foreach ($this->policy->violations((string) $value) as [$message, $parameters]) {
            $builder = $this->context->buildViolation($message);
            foreach ($parameters as $name => $parameter) {
                $builder->setParameter($name, $parameter);
            }
            $builder->addViolation();
        }
    }
}
