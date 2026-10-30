<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use App\Entity\AssetGroup;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps `VulnerabilityOccurrence.effectiveRiskScore` in sync with its three inputs:
 *   - AssetGroup.criticality / exposure / dataSensitivity
 *   - Vulnerability.severity / isActivelyExploited
 *   - the occurrence's own (re)association with either above entity
 *
 * For fresh occurrences (insertions), the computed score is written onto the entity
 * during onFlush so the INSERT statement carries the correct value.
 * For existing occurrences that change indirectly (because their parent AssetGroup or
 * Vulnerability was modified), the score is pushed via a direct SQL update in
 * postFlush — this sidesteps UoW cascade limitations for pure denormalisation fields.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class EffectiveRiskListener implements ResetInterface
{
    /** @var list<array{id: int, score: int}> */
    private array $pendingUpdates = [];

    /**
     * Drops any leftover entries before the next request reuses the listener.
     * Required under FrankenPHP worker mode (or any long-lived process), where
     * an exception between onFlush and postFlush would otherwise leak pending
     * updates into the following request and write the wrong score there.
     * Auto-tagged via ResetInterface (services.yaml autoconfigure).
     */
    public function reset(): void
    {
        $this->pendingUpdates = [];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, VulnerabilityOccurrence> $affected */
        $affected = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof VulnerabilityOccurrence) {
                $affected[spl_object_id($entity)] = $entity;
            }
        }

        $occurrenceRepo = $em->getRepository(VulnerabilityOccurrence::class);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof VulnerabilityOccurrence) {
                $affected[spl_object_id($entity)] = $entity;
                continue;
            }

            if ($entity instanceof AssetGroup) {
                $changes = $uow->getEntityChangeSet($entity);
                if (!isset($changes['criticality']) && !isset($changes['exposure']) && !isset($changes['dataSensitivity'])) {
                    continue;
                }
                // Query explicitly — the inverse collection on AssetGroup may not be initialised at flush time.
                foreach ($occurrenceRepo->findBy(['assetGroup' => $entity]) as $occurrence) {
                    $affected[spl_object_id($occurrence)] = $occurrence;
                }
                continue;
            }

            if ($entity instanceof Vulnerability) {
                $changes = $uow->getEntityChangeSet($entity);
                if (!isset($changes['severity']) && !isset($changes['isActivelyExploited'])) {
                    continue;
                }
                foreach ($occurrenceRepo->findBy(['vulnerability' => $entity]) as $occurrence) {
                    $affected[spl_object_id($occurrence)] = $occurrence;
                }
            }
        }

        foreach ($affected as $occurrence) {
            $previous = $occurrence->getEffectiveRiskScore();
            $newScore = $occurrence->recomputeEffectiveRiskScore();

            if ($newScore === $previous) {
                continue;
            }

            // Inserts & direct occurrence updates already carry the property into the flushed statement.
            if (null === $previous || [] !== $uow->getEntityChangeSet($occurrence)) {
                $uow->recomputeSingleEntityChangeSet(
                    $em->getClassMetadata(VulnerabilityOccurrence::class),
                    $occurrence,
                );
                continue;
            }

            // Cross-entity case (AssetGroup or Vulnerability changed) — schedule a post-flush SQL push.
            $this->pendingUpdates[] = ['id' => $occurrence->getId(), 'score' => $newScore];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingUpdates) {
            return;
        }

        $conn = $args->getObjectManager()->getConnection();
        foreach ($this->pendingUpdates as $pending) {
            $conn->executeStatement(
                'UPDATE vulnerability_occurrence SET effective_risk_score = :score WHERE id = :id',
                $pending,
            );
        }

        $this->pendingUpdates = [];
    }
}
