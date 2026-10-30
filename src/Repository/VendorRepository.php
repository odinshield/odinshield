<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vendor>
 */
class VendorRepository extends ServiceEntityRepository
{
    private const array SORTABLE_COLUMNS = ['name', 'vendorKey', 'website', 'advisoryUrl'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    /**
     * @return Paginator<Vendor>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction, string $filter = 'all'): Paginator
    {
        $sort = \in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('v')
            ->orderBy('v.'.$sort, $order);

        if ('' !== $search) {
            $qb->andWhere('LOWER(v.name) LIKE :search OR LOWER(v.vendorKey) LIKE :search OR LOWER(v.website) LIKE :search OR LOWER(v.advisoryUrl) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if ('active' === $filter) {
            $qb->andWhere('v.active = true');
        } elseif ('inactive' === $filter) {
            $qb->andWhere('v.active = false');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
