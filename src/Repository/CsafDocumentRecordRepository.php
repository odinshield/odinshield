<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\CsafDocumentRecord;
use App\Enum\CsafDocumentOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CsafDocumentRecord>
 */
class CsafDocumentRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CsafDocumentRecord::class);
    }

    /**
     * Everything the pending-set computation needs, keyed by document URL (issue #319).
     *
     * Scalars instead of entities on purpose: Red Hat alone holds about 25k records within the
     * retention horizon, and hydrating them as managed entities every run would cost memory and
     * flush time for rows that are only compared. The runner loads the few records it actually
     * changes with findOneByDocument().
     *
     * @return array<string, array{feedUpdated: \DateTimeImmutable, outcome: CsafDocumentOutcome, attempts: int, contentChangedAt: ?\DateTimeImmutable}>
     */
    public function findHandled(string $providerKey): array
    {
        /** @var list<array{url: string, feedUpdated: \DateTimeImmutable, outcome: CsafDocumentOutcome, attempts: int, contentChangedAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.url, r.feedUpdated, r.outcome, r.attempts, r.contentChangedAt')
            ->andWhere('r.providerKey = :providerKey')
            ->setParameter('providerKey', $providerKey)
            ->orderBy('r.url', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        $handled = [];
        foreach ($rows as $row) {
            $url = $row['url'];
            unset($row['url']);
            $handled[$url] = $row;
        }

        return $handled;
    }

    public function findOneByDocument(string $providerKey, string $url): ?CsafDocumentRecord
    {
        return $this->findOneBy(['providerKey' => $providerKey, 'url' => $url]);
    }

    /**
     * Deletes records whose feed stamp lies before the retention horizon, for all providers.
     *
     * Pruned by the feed stamp, not by when the record was handled: the pending set only looks at
     * feed entries at or after the horizon, so a record older than that can never decide anything
     * again. Records with outcome `failed` go too — their documents have left the horizon as well.
     *
     * @return int the number of deleted records
     */
    public function pruneBefore(\DateTimeImmutable $horizon): int
    {
        /** @var int $deleted */
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(CsafDocumentRecord::class, 'r')
            ->andWhere('r.feedUpdated < :horizon')
            ->setParameter('horizon', $horizon->setTimezone(new \DateTimeZone('UTC')))
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * Read-volume figures per provider for /import-status and provider-list (#319), in one query:
     * documents handled since $recentSince, of those processed (handed to the upserter), and the
     * documents currently failing (still retried) or given up. Providers without records are
     * absent from the result.
     *
     * @return array<string, array{handledRecently: int, processedRecently: int, failing: int, givenUp: int}>
     */
    public function figuresByProvider(\DateTimeImmutable $recentSince, int $maxAttempts): array
    {
        /** @var list<array{provider_key: string, handled_recently: int|string, processed_recently: int|string, failing: int|string, given_up: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT provider_key,
                    COUNT(*) FILTER (WHERE handled_at >= :recentSince) AS handled_recently,
                    COUNT(*) FILTER (WHERE handled_at >= :recentSince AND outcome = :processed) AS processed_recently,
                    COUNT(*) FILTER (WHERE outcome = :failed AND attempts < :maxAttempts) AS failing,
                    COUNT(*) FILTER (WHERE outcome = :failed AND attempts >= :maxAttempts) AS given_up
               FROM csaf_document_record
           GROUP BY provider_key',
            [
                'recentSince' => $recentSince->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'processed' => CsafDocumentOutcome::Processed->value,
                'failed' => CsafDocumentOutcome::Failed->value,
                'maxAttempts' => $maxAttempts,
            ],
        );

        $figures = [];
        foreach ($rows as $row) {
            $figures[$row['provider_key']] = [
                'handledRecently' => (int) $row['handled_recently'],
                'processedRecently' => (int) $row['processed_recently'],
                'failing' => (int) $row['failing'],
                'givenUp' => (int) $row['given_up'],
            ];
        }

        return $figures;
    }

    /**
     * The documents a provider has given up on (#319), newest failure first — listed on
     * /import-status so a give-up is never silent.
     *
     * @return list<CsafDocumentRecord>
     */
    public function findGivenUp(string $providerKey, int $maxAttempts, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.providerKey = :providerKey')
            ->andWhere('r.outcome = :failed')
            ->andWhere('r.attempts >= :maxAttempts')
            ->setParameter('providerKey', $providerKey)
            ->setParameter('failed', CsafDocumentOutcome::Failed->value)
            ->setParameter('maxAttempts', $maxAttempts)
            ->orderBy('r.handledAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * `odinshield:csaf:rewind` (#319): forgets a provider's records whose feed stamp lies at or
     * after $since, so those documents are pending again on the next run.
     *
     * @return int the number of forgotten records
     */
    public function forgetSince(string $providerKey, \DateTimeImmutable $since): int
    {
        /** @var int $deleted */
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(CsafDocumentRecord::class, 'r')
            ->andWhere('r.providerKey = :providerKey')
            ->andWhere('r.feedUpdated >= :since')
            ->setParameter('providerKey', $providerKey)
            ->setParameter('since', $since->setTimezone(new \DateTimeZone('UTC')))
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * Bootstrap (#319): records feed entries as handled without downloading them — the documents
     * the old watermark regime has already read. Plain SQL in chunks rather than entities: on Red
     * Hat this is tens of thousands of rows in one run, and the runner must not clear the entity
     * manager (it would detach the software list). Existing records are left untouched.
     *
     * @param list<array{url: string, updated: \DateTimeImmutable}> $entries
     *
     * @return int the number of inserted records
     */
    public function insertHandledWithoutDownload(string $providerKey, array $entries, \DateTimeImmutable $handledAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $utc = new \DateTimeZone('UTC');
        $handled = $handledAt->setTimezone($utc)->format('Y-m-d H:i:s');
        $inserted = 0;

        foreach (array_chunk($entries, 500) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $entry) {
                $rows[] = '(?, ?, ?, ?, ?, 0)';
                array_push(
                    $params,
                    $providerKey,
                    $entry['url'],
                    $entry['updated']->setTimezone($utc)->format('Y-m-d H:i:s'),
                    $handled,
                    CsafDocumentOutcome::Processed->value,
                );
            }

            $inserted += (int) $connection->executeStatement(
                'INSERT INTO csaf_document_record (provider_key, url, feed_updated, handled_at, outcome, attempts) VALUES '
                .implode(', ', $rows)
                .' ON CONFLICT (provider_key, url) DO NOTHING',
                $params,
            );
        }

        return $inserted;
    }

    public function save(CsafDocumentRecord $record, bool $flush = false): void
    {
        $this->getEntityManager()->persist($record);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
