<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Doctrine\DBAL\Connection;

/**
 * Global, cross-entity audit reader for the compliance timeline (issue #32).
 *
 * The auditor bundle's own Reader only queries ONE `*_audit` table at a time, so a correctly
 * sorted, paginated and counted timeline across all audited entities is built here via a raw
 * DBAL `UNION ALL` over the per-entity audit tables. Rows are hydrated back into the bundle's
 * read model ({@see Entry}) so the existing timeline template renders them unchanged.
 *
 * Only ever used behind a ROLE_ADMIN gate (AuditController::index) — it reads every entity's
 * audit trail without the per-entity access filtering of the single-entity timeline.
 */
final class AuditTimelineReader
{
    /**
     * Slug => entity FQCN. Slugs mirror AuditController::ENTITY_MAP plus `vulnerability_occurrence`
     * (audited but absent there). Order defines the entity-type filter option order.
     *
     * @var array<string, class-string>
     */
    public const array ENTITY_TYPES = [
        'vulnerability' => Vulnerability::class,
        'vulnerability_occurrence' => VulnerabilityOccurrence::class,
        'software' => Software::class,
        'asset_group' => AssetGroup::class,
        'asset_type' => AssetType::class,
        'vendor' => Vendor::class,
        'user' => User::class,
        'user_group' => UserGroup::class,
    ];

    private const string COLUMNS = 'id, type, object_id, discriminator, transaction_hash, diffs, extra_data, blame_id, blame_user, blame_user_fqdn, blame_user_firewall, ip, created_at';

    public function __construct(
        private readonly Connection $connection,
        private readonly Reader $reader,
        private readonly AuditLabelResolver $labelResolver,
    ) {
    }

    /**
     * @return array{items: list<array{entity_type: string, class: class-string, entry: Entry}>, total: int}
     */
    public function search(AuditTimelineFilter $filter, int $page, int $limit): array
    {
        $pairs = $this->slugTablePairs($filter->entityType);
        if ([] === $pairs) {
            return ['items' => [], 'total' => 0];
        }

        [$where, $params] = $this->buildWhere($filter);

        $items = $this->fetchItems($pairs, $where, $params, $page, $limit);

        $countSql = sprintf('SELECT COUNT(*) FROM (%s) c', $this->union($pairs, $where, withEntityType: false));
        $total = (int) $this->connection->executeQuery($countSql, $params)->fetchOne();

        // Resolve object names for the whole page in one bundled pass (issue #64) — the template
        // reads them per row from the warm cache, so the query count stays independent of $limit.
        $this->warmLabels($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Same result set as {@see search()}, chunked and without the COUNT — for the CSV export,
     * which walks the whole filtered trail. Object labels are warmed per chunk and dropped
     * again afterwards so a large export does not grow the cache without bound.
     *
     * @return \Generator<int, array{entity_type: string, class: class-string, entry: Entry}>
     */
    public function stream(AuditTimelineFilter $filter, int $chunkSize = 500): \Generator
    {
        $pairs = $this->slugTablePairs($filter->entityType);
        if ([] === $pairs) {
            return;
        }

        [$where, $params] = $this->buildWhere($filter);

        $page = 1;
        do {
            $items = $this->fetchItems($pairs, $where, $params, $page, $chunkSize);
            $this->labelResolver->clear();
            $this->warmLabels($items);

            yield from $items;

            ++$page;
        } while (\count($items) === $chunkSize);
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @param array<string, string>             $params
     *
     * @return list<array{entity_type: string, class: class-string, entry: Entry}>
     */
    private function fetchItems(array $pairs, string $where, array $params, int $page, int $limit): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $sql = sprintf(
            'SELECT * FROM (%s) t ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
            $this->union($pairs, $where, withEntityType: true),
            $limit,
            $offset,
        );
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $entityType = (string) $row['entity_type'];
            unset($row['entity_type']);
            if (null !== $row['created_at']) {
                $row['created_at'] = new \DateTimeImmutable((string) $row['created_at']);
            }
            $items[] = [
                'entity_type' => $entityType,
                'class' => self::ENTITY_TYPES[$entityType],
                'entry' => Entry::fromArray($row),
            ];
        }

        return $items;
    }

    /**
     * @param list<array{entity_type: string, class: class-string, entry: Entry}> $items
     */
    private function warmLabels(array $items): void
    {
        $this->labelResolver->warmFromEntries(array_map(
            static fn (array $item): array => [$item['class'], $item['entry']],
            $items,
        ));
    }

    /**
     * Distinct actors (blame_user) across all audit tables — feeds the user filter dropdown.
     *
     * @return list<string>
     */
    public function distinctUsers(): array
    {
        $parts = [];
        foreach (self::ENTITY_TYPES as $fqcn) {
            $parts[] = sprintf('SELECT blame_user FROM %s WHERE blame_user IS NOT NULL', $this->reader->getEntityAuditTableName($fqcn));
        }
        $sql = sprintf('SELECT blame_user FROM (%s) u ORDER BY blame_user', implode(' UNION ', $parts));

        /** @var list<string> $users */
        $users = $this->connection->executeQuery($sql)->fetchFirstColumn();

        return $users;
    }

    /**
     * [slug, audit-table-name] pairs to union — one per entity type, or just the one selected by
     * the entity-type filter. Table names are the quote-safe computed names from the auditor config.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function slugTablePairs(?string $entityType): array
    {
        $slugs = null !== $entityType && isset(self::ENTITY_TYPES[$entityType])
            ? [$entityType]
            : array_keys(self::ENTITY_TYPES);

        return array_map(
            fn (string $slug): array => [$slug, $this->reader->getEntityAuditTableName(self::ENTITY_TYPES[$slug])],
            $slugs,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs [slug, table] pairs
     */
    private function union(array $pairs, string $where, bool $withEntityType): string
    {
        $parts = [];
        foreach ($pairs as [$slug, $table]) {
            $select = $withEntityType
                ? sprintf('SELECT %s AS entity_type, %s FROM %s', $this->connection->quote($slug), self::COLUMNS, $table)
                : sprintf('SELECT id FROM %s', $table);
            $parts[] = $select.$where;
        }

        return implode(' UNION ALL ', $parts);
    }

    /**
     * @return array{0: string, 1: array<string, string>} [" WHERE …" clause, named params]
     */
    private function buildWhere(AuditTimelineFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if (null !== $filter->action) {
            $conditions[] = 'type = :action';
            $params['action'] = $filter->action;
        }
        if (null !== $filter->user) {
            $conditions[] = 'blame_user = :user';
            $params['user'] = $filter->user;
        }
        if (null !== $filter->dateFrom) {
            $conditions[] = 'created_at >= :date_from';
            $params['date_from'] = $filter->dateFrom->format('Y-m-d H:i:s');
        }
        if (null !== $filter->dateTo) {
            $conditions[] = 'created_at <= :date_to';
            $params['date_to'] = $filter->dateTo->format('Y-m-d H:i:s');
        }

        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);

        return [$where, $params];
    }
}
