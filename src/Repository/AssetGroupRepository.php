<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\AssetGroup;
use App\Entity\User;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetGroup>
 */
class AssetGroupRepository extends ServiceEntityRepository
{
    private const array SORTABLE_COLUMNS = ['name', 'assetType', 'active', 'criticality', 'exposure', 'dataSensitivity'];

    /**
     * #65 (Q6): the three classification axes are scales, not labels. Sorting them by the stored
     * string would order them alphabetically ('critical', 'high', 'low', 'medium') — visibly wrong
     * to anyone reading the column. They sort by the enum's own weight instead, the same weight
     * that feeds the effective-risk score (BSI-CS 093 §3.3).
     *
     * @var array<string, class-string<AssetCriticality|AssetExposure|DataSensitivity>>
     */
    private const array WEIGHTED_SORTS = [
        'criticality' => AssetCriticality::class,
        'exposure' => AssetExposure::class,
        'dataSensitivity' => DataSensitivity::class,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetGroup::class);
    }

    /**
     * @return Paginator<AssetGroup>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction, string $filter, string $status = 'all', ?User $scopedUser = null, bool $unassignedOnly = false): Paginator
    {
        $sort = \in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('ag')
            ->join('ag.assetType', 'at');

        if ('assetType' === $sort) {
            $qb->orderBy('at.name', $order);
        } elseif (isset(self::WEIGHTED_SORTS[$sort])) {
            $cases = [];
            foreach (self::WEIGHTED_SORTS[$sort]::cases() as $index => $case) {
                $parameter = 'weight_'.$sort.'_'.$index;
                $cases[] = sprintf('WHEN ag.%s = :%s THEN %d', $sort, $parameter, $case->weight());
                $qb->setParameter($parameter, $case->value);
            }

            $qb->addSelect('CASE '.implode(' ', $cases).' ELSE 0 END AS HIDDEN weight_sort')
                ->orderBy('weight_sort', $order)
                ->addOrderBy('ag.name', \SortDirection::Ascending);
        } else {
            $qb->orderBy('ag.'.$sort, $order);
        }

        if ('' !== $search) {
            $qb->andWhere('LOWER(ag.name) LIKE :search OR LOWER(ag.description) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if ('all' !== $filter) {
            $qb->andWhere('at.uuid = :filter')
                ->setParameter('filter', $filter);
        }

        if ('active' === $status) {
            $qb->andWhere('ag.active = true');
        } elseif ('inactive' === $status) {
            $qb->andWhere('ag.active = false');
        }

        if (null !== $scopedUser) {
            $qb->andWhere('ag.responsibleUser = :scopedUser')
                ->setParameter('scopedUser', $scopedUser);
        }

        // #66: the counterpart to the equality above — asset groups nobody owns.
        if ($unassignedOnly) {
            $qb->andWhere('ag.responsibleUser IS NULL');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    public function isResponsibleForAnyAssetGroup(User $user): bool
    {
        return $this->countResponsibleAssetGroups($user) > 0;
    }

    public function countResponsibleAssetGroups(User $user): int
    {
        return (int) $this->createQueryBuilder('ag')
            ->select('COUNT(ag.id)')
            ->andWhere('ag.active = true')
            ->andWhere('ag.responsibleUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * #66: asset groups nobody is responsible for. Counts ACTIVE groups only — an inactive group
     * without an owner is not work anybody is failing to do.
     */
    public function countWithoutResponsibleUser(): int
    {
        return (int) $this->createQueryBuilder('ag')
            ->select('COUNT(ag.id)')
            ->andWhere('ag.active = true')
            ->andWhere('ag.responsibleUser IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
