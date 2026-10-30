<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the aftermath of a Doctrine OptimisticLockException.
 *
 * Doctrine closes the EntityManager when the version check fails, so `refresh()`
 * and any other write API are unavailable. This service:
 *   1. Reads audit metadata from the still-functioning DBAL connection.
 *   2. Resets the manager so the rest of the request gets a fresh EM. Doctrine's
 *      `resetManager()` reuses the same EM instance (only clears its identity map +
 *      reopens it) — Symfony's form-factory choice list cache uses
 *      `spl_object_hash($em)` as a cache key, so the cache key is unchanged but the
 *      cached `ArrayChoiceList` now references detached entities that fail the
 *      "contains" check during render. Resetting the form choice list factory and
 *      DoctrineType caches in lock-step with the EM avoids this.
 *   3. Loads a fresh copy of the entity via the new EM so the caller can re-render
 *      the form with the *other* user's persisted state.
 */
final readonly class OptimisticLockConflictResolver
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        // CachingFactoryDecorator + DoctrineType both cache choice lists / IdReaders keyed
        // by EM-instance hash; they must be reset alongside the EM, otherwise stale detached
        // entities survive into the conflict re-render and fail the "contains" check.
        #[Autowire(service: 'form.choice_list_factory.cached')]
        private ResetInterface $choiceListFactory,
        #[Autowire(service: 'form.type.entity')]
        private ResetInterface $doctrineType,
    ) {
    }

    /**
     * @template T of object
     *
     * @param T $entity the (now-stale) entity from the closed EntityManager
     *
     * @return array{
     *     fresh: T,
     *     updatedBy: ?string,
     *     updatedAt: ?\DateTimeImmutable
     * }
     */
    public function resolve(object $entity): array
    {
        $class = $entity::class;
        $meta = $this->em->getClassMetadata($class);
        $idValue = $meta->getFieldValue($entity, 'id');

        $connection = $this->em->getConnection();
        // The quote strategy honours the backtick mapping — `user` is a reserved word in
        // PostgreSQL, and the bare name resolves to the current_user function instead.
        $table = $this->em->getConfiguration()->getQuoteStrategy()->getTableName($meta, $connection->getDatabasePlatform());

        $row = $connection->fetchAssociative(
            sprintf('SELECT updated_by, updated_at FROM %s WHERE id = ?', $table),
            [$idValue],
        );

        $this->registry->resetManager();
        $this->choiceListFactory->reset();
        $this->doctrineType->reset();

        $freshEm = $this->registry->getManagerForClass($class) ?? $this->registry->getManager();
        $fresh = $freshEm->find($class, $idValue);

        if (null === $fresh) {
            // Row was deleted between conflict and recovery. Return the stale entity so the
            // caller can still render something; lockVersion stays at the submitted value.
            $fresh = $entity;
        }

        $updatedAt = null;
        if (false !== $row && isset($row['updated_at']) && '' !== $row['updated_at']) {
            $updatedAt = new \DateTimeImmutable((string) $row['updated_at']);
        }

        return [
            'fresh' => $fresh,
            'updatedBy' => (false !== $row && isset($row['updated_by'])) ? (string) $row['updated_by'] : null,
            'updatedAt' => $updatedAt,
        ];
    }
}
