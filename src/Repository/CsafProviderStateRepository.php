<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\CsafProviderState;
use App\Enum\CsafProviderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CsafProviderState>
 */
class CsafProviderStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CsafProviderState::class);
    }

    /**
     * @return list<CsafProviderState>
     */
    public function findRunnable(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', CsafProviderStatus::Enabled->value)
            ->orderBy('p.providerKey', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Providers the circuit breaker has paused (issue #63).
     *
     * findRunnable() deliberately excludes these — they must not be fetched. But they must still
     * be *reminded about*, which is why they need their own query: without it the auto-pause mail
     * fires exactly once and nothing ever follows up.
     *
     * @return list<CsafProviderState>
     */
    public function findAutoPaused(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', CsafProviderStatus::AutoPaused->value)
            ->orderBy('p.providerKey', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CsafProviderState>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.providerKey', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function save(CsafProviderState $state, bool $flush = false): void
    {
        $this->getEntityManager()->persist($state);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
