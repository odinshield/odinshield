<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Software;

use App\Entity\AssetGroup;
use App\Entity\Software;
use App\Enum\PredecessorAction;
use App\Exception\SoftwareSuccessorAlreadyExistsException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a successor `Software` row for a given predecessor and applies the
 * caller-selected asset-group migration plan + predecessor-lifecycle action,
 * all in a single transaction.
 *
 * Vulnerabilities are intentionally NOT carried over: a new version starts
 * with an empty CVE list. The next vulnerability scan / manual review re-attaches
 * what actually still applies — most CVEs from the old version are typically
 * fixed by the upgrade.
 */
final class SoftwareSuccessorService
{
    public const string AG_MIGRATE = 'migrate';
    public const string AG_KEEP = 'keep';
    public const string AG_BOTH = 'both';

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<int, self::AG_*> $assetGroupChoices keyed by AssetGroup.id
     */
    public function createSuccessor(
        Software $predecessor,
        Software $draftSuccessor,
        array $assetGroupChoices,
        PredecessorAction $predecessorAction,
    ): Software {
        $now = new \DateTimeImmutable();

        try {
            return $this->em->wrapInTransaction(function () use (
                $predecessor,
                $draftSuccessor,
                $assetGroupChoices,
                $predecessorAction,
                $now,
            ): Software {
                $draftSuccessor->setPredecessor($predecessor);
                $this->em->persist($draftSuccessor);

                $this->applyAssetGroupChoices($predecessor, $draftSuccessor, $assetGroupChoices);
                $this->applyPredecessorAction($predecessor, $predecessorAction, $now);

                $this->em->flush();

                return $draftSuccessor;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new SoftwareSuccessorAlreadyExistsException(sprintf('Software version "%s" already exists for vendor "%s" / product "%s".', $draftSuccessor->getVersion(), $draftSuccessor->getVendor()->getName(), $draftSuccessor->getProductKey()), previous: $e);
        }
    }

    /**
     * @param array<int, self::AG_*> $choices
     *
     * @return array<int, AssetGroup> AGs that landed on the successor (migrate + both), keyed by AssetGroup.id
     */
    private function applyAssetGroupChoices(
        Software $predecessor,
        Software $successor,
        array $choices,
    ): array {
        $successorAssetGroups = [];

        foreach ($predecessor->getAssetGroups() as $assetGroup) {
            $choice = $choices[$assetGroup->getId()] ?? self::AG_KEEP;

            if (self::AG_MIGRATE === $choice) {
                $predecessor->removeAssetGroup($assetGroup);
                $successor->addAssetGroup($assetGroup);
                $successorAssetGroups[$assetGroup->getId()] = $assetGroup;
            } elseif (self::AG_BOTH === $choice) {
                $successor->addAssetGroup($assetGroup);
                $successorAssetGroups[$assetGroup->getId()] = $assetGroup;
            }
            // AG_KEEP → no-op
        }

        return $successorAssetGroups;
    }

    private function applyPredecessorAction(
        Software $predecessor,
        PredecessorAction $action,
        \DateTimeImmutable $now,
    ): void {
        $predecessor->setSupersededAt($now);

        match ($action) {
            PredecessorAction::Active => null,
            PredecessorAction::Inactive => $predecessor->setActive(false),
            PredecessorAction::Eol => $this->applyEol($predecessor, $now),
        };
    }

    private function applyEol(Software $predecessor, \DateTimeImmutable $now): void
    {
        $predecessor->setActive(false);
        if (null === $predecessor->getEolDate()) {
            $predecessor->setEolDate($now);
        }
    }
}
