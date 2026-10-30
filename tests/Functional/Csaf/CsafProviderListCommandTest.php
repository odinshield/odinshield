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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #319: `odinshield:csaf:provider-list` shows the same read volume and "reads nothing" rule as
 * /import-status — the operator on the console must see a silent loss as well.
 */
final class CsafProviderListCommandTest extends KernelTestCase
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

    public function testShowsTheReadVolumeAndFlagsAProviderThatReadsNothing(): void
    {
        $reading = new CsafProviderState('reading', 'Reading Provider', 'https://reading.test/metadata.json');
        $reading->setRecentFeedEntries(2);
        $reading->setPendingDocuments(0);
        $this->em->persist($reading);
        $this->em->persist(CsafDocumentRecord::handled('reading', 'https://reading.test/a.json', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable(), CsafDocumentOutcome::Processed));
        $silent = new CsafProviderState('silent', 'Silent Provider', 'https://silent.test/metadata.json');
        $silent->setRecentFeedEntries(40);
        $this->em->persist($silent);
        $this->em->flush();

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('odinshield:csaf:provider-list'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Read 7d', $display);
        self::assertMatchesRegularExpression('/reading .*Enabled +.* 2 +1 +0 +0 +0/', $display);
        self::assertMatchesRegularExpression('/silent .*Enabled \(reads nothing!\)/', $display);
        self::assertStringContainsString('reads nothing', $display);
    }
}
