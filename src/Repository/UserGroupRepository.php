<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\UserGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroup>
 */
class UserGroupRepository extends ServiceEntityRepository
{
    private const array SORTABLE_COLUMNS = ['name'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroup::class);
    }

    /**
     * @return Paginator<UserGroup>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction): Paginator
    {
        $sort = \in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('ug')
            ->orderBy('ug.'.$sort, $order);

        if ('' !== $search) {
            $qb->andWhere('LOWER(ug.name) LIKE :search OR LOWER(ug.description) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
