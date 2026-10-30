<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\UpdateCheckState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpdateCheckState>
 */
class UpdateCheckStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdateCheckState::class);
    }

    /**
     * Read-only counterpart to {@see self::getOrCreate()} — the dashboard runs on a GET and must
     * not create the row.
     */
    public function findState(): ?UpdateCheckState
    {
        return $this->find(UpdateCheckState::ID);
    }

    /**
     * Returns the single state row, creating and persisting it on first use.
     */
    public function getOrCreate(): UpdateCheckState
    {
        $state = $this->find(UpdateCheckState::ID);
        if (null !== $state) {
            return $state;
        }

        $state = new UpdateCheckState();
        $this->getEntityManager()->persist($state);
        $this->getEntityManager()->flush();

        return $state;
    }
}
