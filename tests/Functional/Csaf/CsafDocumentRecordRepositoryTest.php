<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Csaf;

use App\Entity\CsafDocumentRecord;
use App\Enum\CsafDocumentOutcome;
use App\Repository\CsafDocumentRecordRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #319: the ledger queries the runner builds on — the per-provider lookup for the pending
 * set and the pruning at the retention horizon.
 */
final class CsafDocumentRecordRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CsafDocumentRecordRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $registry->getManager();
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->repository = $this->em->getRepository(CsafDocumentRecord::class);
    }

    public function testFindHandledIsKeyedByUrlAndLimitedToTheProvider(): void
    {
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/a.json', new \DateTimeImmutable('2026-10-20T22:00:00Z'), new \DateTimeImmutable('2026-10-21T04:40:00Z'), CsafDocumentOutcome::Processed, 'WID-SEC-W-2026-1', '1', new \DateTimeImmutable('2026-10-21T08:00:00Z')));
        $this->repository->save(CsafDocumentRecord::failed('bsi_wid', 'https://bsi.test/b.json', new \DateTimeImmutable('2026-10-21T22:00:00Z'), new \DateTimeImmutable('2026-10-22T04:40:00Z'), 'HTTP 503'));
        $this->repository->save(CsafDocumentRecord::handled('other', 'https://other.test/c.json', new \DateTimeImmutable('2026-10-21T10:00:00Z'), new \DateTimeImmutable('2026-10-22T04:40:00Z'), CsafDocumentOutcome::Rebuild));
        $this->em->flush();
        $this->em->clear();

        $handled = $this->repository->findHandled('bsi_wid');

        self::assertSame(['https://bsi.test/a.json', 'https://bsi.test/b.json'], array_keys($handled));
        self::assertSame(CsafDocumentOutcome::Processed, $handled['https://bsi.test/a.json']['outcome']);
        self::assertEquals(new \DateTimeImmutable('2026-10-20T22:00:00Z'), $handled['https://bsi.test/a.json']['feedUpdated']);
        self::assertEquals(new \DateTimeImmutable('2026-10-21T08:00:00Z'), $handled['https://bsi.test/a.json']['contentChangedAt']);
        self::assertSame(0, $handled['https://bsi.test/a.json']['attempts']);
        self::assertSame(CsafDocumentOutcome::Failed, $handled['https://bsi.test/b.json']['outcome']);
        self::assertSame(1, $handled['https://bsi.test/b.json']['attempts']);
        self::assertNull($handled['https://bsi.test/b.json']['contentChangedAt']);
    }

    public function testAnOffsetStampSurvivesTheRoundTrip(): void
    {
        // Schneider-style stamp with an offset: what the pending set compares after a reload must
        // be the same instant, not the same wall-clock time.
        $this->repository->save(CsafDocumentRecord::handled('schneider', 'https://se.test/a.json', new \DateTimeImmutable('2026-10-21T09:00:00+02:00'), new \DateTimeImmutable('2026-10-21T12:00:00Z'), CsafDocumentOutcome::Processed), true);
        $this->em->clear();

        $handled = $this->repository->findHandled('schneider');

        self::assertSame((new \DateTimeImmutable('2026-10-21T07:00:00Z'))->getTimestamp(), $handled['https://se.test/a.json']['feedUpdated']->getTimestamp());
    }

    public function testFindOneByDocument(): void
    {
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/a.json', new \DateTimeImmutable('2026-10-20T22:00:00Z'), new \DateTimeImmutable('2026-10-21T04:40:00Z'), CsafDocumentOutcome::Processed), true);
        $this->em->clear();

        self::assertNotNull($this->repository->findOneByDocument('bsi_wid', 'https://bsi.test/a.json'));
        self::assertNull($this->repository->findOneByDocument('other', 'https://bsi.test/a.json'));
    }

    public function testOneRecordPerProviderAndUrl(): void
    {
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/a.json', new \DateTimeImmutable('2026-10-20T22:00:00Z'), new \DateTimeImmutable('2026-10-21T04:40:00Z'), CsafDocumentOutcome::Processed), true);
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/a.json', new \DateTimeImmutable('2026-10-21T22:00:00Z'), new \DateTimeImmutable('2026-10-22T04:40:00Z'), CsafDocumentOutcome::Processed));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testFiguresCountRecentHandlingFailingAndGivenUpPerProvider(): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/a.json', $now->modify('-1 day'), $now, CsafDocumentOutcome::Processed));
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/b.json', $now->modify('-1 day'), $now, CsafDocumentOutcome::Skipped));
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/old.json', $now->modify('-30 days'), $now->modify('-20 days'), CsafDocumentOutcome::Processed));
        $this->repository->save(CsafDocumentRecord::failed('bsi_wid', 'https://bsi.test/c.json', $now->modify('-1 day'), $now->modify('-10 days'), 'HTTP 503'));
        $givenUp = CsafDocumentRecord::failed('bsi_wid', 'https://bsi.test/d.json', $now->modify('-1 day'), $now->modify('-10 days'), 'HTTP 404');
        $givenUp->recordFailure($now->modify('-1 day'), $now->modify('-9 days'), 'HTTP 404');
        $this->repository->save($givenUp);
        $this->repository->save(CsafDocumentRecord::handled('other', 'https://other.test/a.json', $now->modify('-1 day'), $now, CsafDocumentOutcome::Rebuild));
        $this->em->flush();

        $figures = $this->repository->figuresByProvider($now->modify('-7 days'), 2);

        self::assertSame(['handledRecently' => 2, 'processedRecently' => 1, 'failing' => 1, 'givenUp' => 1], $figures['bsi_wid']);
        self::assertSame(['handledRecently' => 1, 'processedRecently' => 0, 'failing' => 0, 'givenUp' => 0], $figures['other']);
        self::assertSame(['https://bsi.test/d.json'], array_map(static fn (CsafDocumentRecord $r): string => $r->getUrl(), $this->repository->findGivenUp('bsi_wid', 2, 10)));
    }

    public function testPruneDeletesByFeedStampAcrossProviders(): void
    {
        $horizon = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        // Old feed stamp but handled recently (bootstrap or rewind): still pruned, the pending
        // set never looks at it again.
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/old.json', new \DateTimeImmutable('2026-07-31T22:00:00Z'), new \DateTimeImmutable('2026-10-29T04:40:00Z'), CsafDocumentOutcome::Processed));
        $this->repository->save(CsafDocumentRecord::failed('other', 'https://other.test/old.json', new \DateTimeImmutable('2026-07-01T10:00:00Z'), new \DateTimeImmutable('2026-07-02T04:40:00Z'), 'HTTP 404'));
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/edge.json', $horizon, new \DateTimeImmutable('2026-08-02T04:40:00Z'), CsafDocumentOutcome::Processed));
        $this->repository->save(CsafDocumentRecord::handled('bsi_wid', 'https://bsi.test/new.json', new \DateTimeImmutable('2026-10-20T22:00:00Z'), new \DateTimeImmutable('2026-10-21T04:40:00Z'), CsafDocumentOutcome::Processed));
        $this->em->flush();

        self::assertSame(2, $this->repository->pruneBefore($horizon));

        self::assertSame(['https://bsi.test/edge.json', 'https://bsi.test/new.json'], array_keys($this->repository->findHandled('bsi_wid')));
        self::assertSame([], $this->repository->findHandled('other'));
    }
}
