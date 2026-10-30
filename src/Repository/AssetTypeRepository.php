<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\AssetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetType>
 */
class AssetTypeRepository extends ServiceEntityRepository
{
    private const array SORTABLE_COLUMNS = ['name', 'active'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetType::class);
    }

    /**
     * @return Paginator<AssetType>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction, string $filter = 'all'): Paginator
    {
        $sort = \in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.'.$sort, $order);

        if ('' !== $search) {
            $qb->andWhere('LOWER(t.name) LIKE :search OR LOWER(t.description) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if ('active' === $filter) {
            $qb->andWhere('t.active = true');
        } elseif ('inactive' === $filter) {
            $qb->andWhere('t.active = false');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /**
     * @param int[] $assetTypeIds
     *
     * @return array<int, int>
     */
    public function countAssetGroupsByAssetTypeIds(array $assetTypeIds): array
    {
        if ([] === $assetTypeIds) {
            return [];
        }

        /** @var array<int, array{assetTypeId: int, groupCount: int}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS assetTypeId, COUNT(g.id) AS groupCount')
            ->leftJoin('t.assetGroups', 'g')
            ->where('t.id IN (:ids)')
            ->groupBy('t.id')
            ->setParameter('ids', $assetTypeIds)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['assetTypeId']] = (int) $row['groupCount'];
        }

        return $counts;
    }
}
