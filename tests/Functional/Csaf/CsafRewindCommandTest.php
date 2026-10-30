<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Csaf;

use App\Entity\CsafDocumentRecord;
use App\Entity\CsafProviderState;
use App\Enum\CsafDocumentOutcome;
use App\Repository\CsafDocumentRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `odinshield:csaf:rewind` re-reads advisories the importer never read (BSI's backdated stamps,
 * #313) or read before the matching software existed. Since #319 it forgets ledger records; a
 * provider not yet on the ledger still gets its old watermark moved back, never forward, so the
 * bootstrap does not record the range as read.
 */
final class CsafRewindCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

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
    }

    public function testForgetsRecordsWithinTheRangeOnly(): void
    {
        $this->persistState(new \DateTimeImmutable('-1 hour'), ledgerSince: new \DateTimeImmutable('-200 days'));
        $this->persistRecord('bsi_wid', 'https://wid.test/recent.json', '-3 days');
        $this->persistRecord('bsi_wid', 'https://wid.test/old.json', '-30 days');
        $this->persistRecord('other', 'https://other.test/recent.json', '-3 days');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['provider' => 'bsi_wid', '--days' => '14']));

        self::assertSame(['https://wid.test/old.json'], array_keys($this->records()->findHandled('bsi_wid')));
        self::assertCount(1, $this->records()->findHandled('other'), 'Other providers are untouched.');
        self::assertStringContainsString('1 documents', $tester->getDisplay());
    }

    public function testLowersLedgerSinceToTheRange(): void
    {
        // Otherwise the rebuild check would treat content within the range as already read.
        $this->persistState(new \DateTimeImmutable('-1 hour'), ledgerSince: new \DateTimeImmutable('-2 days'));

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['provider' => 'bsi_wid', '--days' => '14']));

        self::assertEqualsWithDelta((new \DateTimeImmutable('-14 days'))->getTimestamp(), $this->reload()->getLedgerSince()?->getTimestamp(), 60);
    }

    public function testNeverRaisesLedgerSince(): void
    {
        $since = new \DateTimeImmutable('2026-01-01 00:00:00');
        $this->persistState(new \DateTimeImmutable('-1 hour'), ledgerSince: $since);

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['provider' => 'bsi_wid', '--days' => '14']));

        self::assertSame($since->getTimestamp(), $this->reload()->getLedgerSince()?->getTimestamp());
    }

    public function testMovesTheWatermarkBackBeforeTheFirstLedgerRun(): void
    {
        $this->persistState(new \DateTimeImmutable('-1 hour'));

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['provider' => 'bsi_wid', '--days' => '14']));

        $watermark = $this->reload()->getLastFetchedAt();
        self::assertNotNull($watermark);
        self::assertEqualsWithDelta((new \DateTimeImmutable('-14 days'))->getTimestamp(), $watermark->getTimestamp(), 60);
        self::assertNull($this->reload()->getLedgerSince(), 'The bootstrap still has to run.');
    }

    public function testNeverMovesAnOlderWatermarkForwardBeforeTheFirstLedgerRun(): void
    {
        $held = new \DateTimeImmutable('2026-09-03 22:00:00');
        $this->persistState($held);

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['provider' => 'bsi_wid', '--days' => '1']));

        self::assertSame($held->getTimestamp(), $this->reload()->getLastFetchedAt()?->getTimestamp());
        self::assertStringContainsString('left unchanged', $tester->getDisplay());
    }

    public function testUnknownProviderFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['provider' => 'nope']));
    }

    public function testDaysBelowOneFails(): void
    {
        $this->persistState(new \DateTimeImmutable('-1 hour'));

        self::assertSame(Command::FAILURE, $this->tester()->execute(['provider' => 'bsi_wid', '--days' => '0']));
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('odinshield:csaf:rewind'));
    }

    private function persistState(\DateTimeImmutable $lastFetchedAt, ?\DateTimeImmutable $ledgerSince = null): void
    {
        $state = new CsafProviderState('bsi_wid', 'BSI Warn- und Informationsdienst', 'https://wid.test/provider-metadata.json');
        $state->setLastFetchedAt($lastFetchedAt);
        $state->setLedgerSince($ledgerSince);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function persistRecord(string $providerKey, string $url, string $feedUpdated): void
    {
        $this->em->persist(CsafDocumentRecord::handled($providerKey, $url, new \DateTimeImmutable($feedUpdated), new \DateTimeImmutable(), CsafDocumentOutcome::Processed));
        $this->em->flush();
    }

    private function records(): CsafDocumentRecordRepository
    {
        $repository = $this->em->getRepository(CsafDocumentRecord::class);
        self::assertInstanceOf(CsafDocumentRecordRepository::class, $repository);

        return $repository;
    }

    private function reload(): CsafProviderState
    {
        $this->em->clear();
        $state = $this->em->find(CsafProviderState::class, 'bsi_wid');
        self::assertInstanceOf(CsafProviderState::class, $state);

        return $state;
    }
}
