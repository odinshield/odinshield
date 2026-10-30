<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\Software;
use App\Entity\User;
use App\Enum\MaintenanceSource;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilityStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Software>
 */
class SoftwareRepository extends ServiceEntityRepository
{
    private const array SORTABLE_COLUMNS = ['name', 'productKey', 'version', 'vendor'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Software::class);
    }

    /**
     * Escape LIKE special characters (backslash first) so user input matches literally.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return Paginator<Software>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction, string $filter = 'all', ?User $scopedUser = null, bool $unassignedOnly = false): Paginator
    {
        $sort = \in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.vendor', 'v')
            ->addSelect('v');

        if ('vendor' === $sort) {
            $qb->orderBy('v.name', $order);
        } else {
            $qb->orderBy('s.'.$sort, $order);
        }

        if ('' !== $search) {
            // Audit L12: escape LIKE wildcards + mb_strtolower for umlaut-correct matching.
            $qb->andWhere('LOWER(s.name) LIKE :search OR LOWER(s.productKey) LIKE :search OR LOWER(s.version) LIKE :search OR LOWER(v.name) LIKE :search')
                ->setParameter('search', '%'.self::escapeLike(mb_strtolower($search)).'%');
        }

        if ('active' === $filter) {
            $qb->andWhere('s.active = true');
        } elseif ('inactive' === $filter) {
            $qb->andWhere('s.active = false');
        }

        if (null !== $scopedUser) {
            $qb->andWhere('s.responsibleUser = :scopedUser')
                ->setParameter('scopedUser', $scopedUser);
        }

        // #66: the counterpart to the equality above — software nobody owns.
        if ($unassignedOnly) {
            $qb->andWhere('s.responsibleUser IS NULL');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /**
     * Software approaching end of support within $window from now.
     * Pass $responsibleUser to restrict results to software the user is responsible for.
     *
     * @return list<Software>
     */
    public function findEndOfSupportWithin(\DateInterval $window, ?User $responsibleUser = null, int $limit = 10): array
    {
        $threshold = (new \DateTimeImmutable())->add($window);

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.vendor', 'v')->addSelect('v')
            ->andWhere('s.active = true')
            ->andWhere('s.eolDate IS NOT NULL')
            ->andWhere('s.eolDate <= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('s.eolDate', \SortDirection::Ascending)
            ->setMaxResults($limit);

        if (null !== $responsibleUser) {
            $qb->andWhere('s.responsibleUser = :user')
                ->setParameter('user', $responsibleUser);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Manually-updated software that has not been reviewed for $staleAfter or longer.
     * Pass $responsibleUser to restrict results to software the user is responsible for.
     *
     * @return list<Software>
     */
    public function findStaleReview(\DateInterval $staleAfter, int $limit = 10, ?User $responsibleUser = null): array
    {
        $cutoff = (new \DateTimeImmutable())->sub($staleAfter);

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.vendor', 'v')->addSelect('v')
            // Never-reviewed software (lastReviewedAt IS NULL) is the most overdue, but Postgres
            // sorts NULLs last on ASC — surface them first via an explicit ordering key (audit M5).
            ->addSelect('CASE WHEN s.lastReviewedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN never_reviewed')
            ->andWhere('s.active = true')
            ->andWhere('s.maintenanceSource = :manual')
            ->andWhere('s.lastReviewedAt IS NULL OR s.lastReviewedAt < :cutoff')
            ->setParameter('manual', MaintenanceSource::Manual->value)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('never_reviewed', \SortDirection::Ascending)
            ->addOrderBy('s.lastReviewedAt', \SortDirection::Ascending)
            ->setMaxResults($limit);

        if (null !== $responsibleUser) {
            $qb->andWhere('s.responsibleUser = :user')
                ->setParameter('user', $responsibleUser);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The inventory the CSAF importer matches against (#134): active, Import-maintained software
     * of an active vendor, with the vendor joined because the matcher reads its key and aliases.
     * The Import gate lives here, upstream of matching — Manual- and None-maintained software is
     * never even compared.
     *
     * @return list<Software>
     */
    public function findAllImportMatchable(): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.vendor', 'v')->addSelect('v')
            ->andWhere('s.active = true')
            ->andWhere('v.active = true')
            ->andWhere('s.maintenanceSource = :import')
            ->setParameter('import', MaintenanceSource::Import)
            ->orderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    public function isResponsibleForAnySoftware(User $user): bool
    {
        return $this->countResponsibleSoftware($user) > 0;
    }

    public function countResponsibleSoftware(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.active = true')
            ->andWhere('s.responsibleUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Aggregates (open-vuln count, worst open severity) for a set of software IDs.
     * Returned as a map keyed by software ID.
     *
     * @param list<int> $softwareIds
     *
     * @return array<int, array{openVulnCount: int, worstSeverity: VulnerabilitySeverity|null}>
     */
    public function getVulnerabilityStatsForSoftwareIds(array $softwareIds): array
    {
        if ([] === $softwareIds) {
            return [];
        }

        $rows = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT s.id AS softwareId, COUNT(DISTINCT v.id) AS openCount,
                   MAX(CASE v.severity
                       WHEN 'critical' THEN 5
                       WHEN 'high' THEN 4
                       WHEN 'medium' THEN 3
                       WHEN 'low' THEN 2
                       WHEN 'informational' THEN 1
                       ELSE 0 END) AS severityWeight
            FROM App\Entity\Software s
            JOIN s.vulnerabilities v
            WHERE v.status IN (:active) AND s.id IN (:ids)
            GROUP BY s.id
        DQL)
            ->setParameter('active', array_map(static fn (VulnerabilityStatus $s) => $s->value, VulnerabilityStatus::activeStatuses()))
            ->setParameter('ids', $softwareIds)
            ->getArrayResult();

        $stats = [];
        $weightToSeverity = [
            5 => VulnerabilitySeverity::Critical,
            4 => VulnerabilitySeverity::High,
            3 => VulnerabilitySeverity::Medium,
            2 => VulnerabilitySeverity::Low,
            1 => VulnerabilitySeverity::Informational,
        ];
        foreach ($rows as $row) {
            $weight = (int) $row['severityWeight'];
            $stats[(int) $row['softwareId']] = [
                'openVulnCount' => (int) $row['openCount'],
                'worstSeverity' => $weightToSeverity[$weight] ?? null,
            ];
        }

        return $stats;
    }

    /**
     * #319 (§4.4 of the ledger plan): which of the given software the CSAF import matches but
     * cannot record anything for — Import-maintained, without any asset group. The upserter skips
     * such a hit with a log notice nobody reads in production; the list marks the row instead.
     * One query for the whole page rather than a lazy collection per row.
     *
     * @param list<int> $softwareIds
     *
     * @return list<int>
     */
    public function findImportWithoutAssetGroupIds(array $softwareIds): array
    {
        if ([] === $softwareIds) {
            return [];
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id')
            ->leftJoin('s.assetGroups', 'ag')
            ->andWhere('s.id IN (:ids)')
            ->andWhere('s.maintenanceSource = :import')
            ->groupBy('s.id')
            ->having('COUNT(ag.id) = 0')
            ->setParameter('ids', $softwareIds)
            ->setParameter('import', MaintenanceSource::Import->value)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * #66: software nobody is responsible for. ACTIVE products only — a retired product without
     * an owner is not an open responsibility.
     */
    public function countWithoutResponsibleUser(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.active = true')
            ->andWhere('s.responsibleUser IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
