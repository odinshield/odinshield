<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class AssetGroupsCoveredBySoftwareValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AssetGroupsCoveredBySoftware) {
            throw new UnexpectedTypeException($constraint, AssetGroupsCoveredBySoftware::class);
        }

        if (!$value instanceof Vulnerability) {
            throw new UnexpectedTypeException($value, Vulnerability::class);
        }

        // Mode 2: no software linked, so no claim about the inventory to contradict.
        if ($value->getSoftware()->isEmpty()) {
            return;
        }

        $newOccurrences = [];
        foreach ($value->getOccurrences() as $occurrence) {
            if (!$this->isPersisted($occurrence)) {
                $newOccurrences[] = $occurrence;
            }
        }

        // Grandfathering: nothing new to judge. An existing occurrence whose inventory
        // link was removed later stays valid on purpose.
        if ([] === $newOccurrences) {
            return;
        }

        $candidates = $value->getCandidateAssetGroups();

        // Dead end: software is selected but the inventory places it in no asset group,
        // so the candidate set is empty and the user cannot pick anything at all. Say so
        // on the software field and name both ways out. The CSAF importer already skips
        // such products (VulnerabilityUpserter::upsert).
        if ([] === $candidates) {
            $this->context->buildViolation($constraint->softwareWithoutAssetGroupsMessage)
                ->atPath('software')
                ->addViolation();

            return;
        }

        foreach ($newOccurrences as $occurrence) {
            $assetGroup = $occurrence->getAssetGroup();
            if (\in_array($assetGroup, $candidates, true)) {
                continue;
            }

            $this->context->buildViolation($constraint->notCoveredMessage)
                ->setParameter('%asset_group%', $assetGroup->getName())
                ->atPath('assetGroups')
                ->addViolation();
        }
    }

    /**
     * An occurrence the entity manager already knows was loaded from the database;
     * anything else was minted by this request and is therefore subject to the rule.
     * Cheaper and more direct than diffing against the original entity data.
     */
    private function isPersisted(VulnerabilityOccurrence $occurrence): bool
    {
        return $this->entityManager->contains($occurrence);
    }
}
