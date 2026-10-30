<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use App\Entity\AssetGroup;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ResponsibleUserAlignedValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ResponsibleUserAligned) {
            throw new UnexpectedTypeException($constraint, ResponsibleUserAligned::class);
        }

        if (!$value instanceof AssetGroup) {
            throw new UnexpectedTypeException($value, AssetGroup::class);
        }

        $responsibleUser = $value->getResponsibleUser();
        if (null === $responsibleUser) {
            return;
        }

        if (!$responsibleUser->isActive()) {
            $this->context->buildViolation($constraint->inactiveMessage)
                ->atPath('responsibleUser')
                ->addViolation();

            return;
        }

        $assetType = $value->getAssetType();
        foreach ($responsibleUser->getUserGroups() as $userGroup) {
            if ($userGroup->getAssetTypes()->contains($assetType)) {
                return;
            }
        }

        $this->context->buildViolation($constraint->notAlignedMessage)
            ->atPath('responsibleUser')
            ->addViolation();
    }
}
