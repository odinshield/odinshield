<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use App\Entity\Vendor;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class VendorInactiveAllowedValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof VendorInactiveAllowed) {
            throw new UnexpectedTypeException($constraint, VendorInactiveAllowed::class);
        }

        if (!$value instanceof Vendor) {
            throw new UnexpectedTypeException($value, Vendor::class);
        }

        if ($value->isActive()) {
            return;
        }

        foreach ($value->getSoftware() as $software) {
            if ($software->isActive()) {
                $this->context->buildViolation($constraint->message)
                    ->atPath('active')
                    ->addViolation();

                return;
            }
        }
    }
}
