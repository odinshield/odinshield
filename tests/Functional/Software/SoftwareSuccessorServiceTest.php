<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Software;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\Vendor;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use App\Enum\PredecessorAction;
use App\Exception\SoftwareSuccessorAlreadyExistsException;
use App\Service\Software\SoftwareSuccessorService;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exercises the AG-migration matrix and predecessor-state lifecycle of
 * SoftwareSuccessorService against a real Postgres test database.
 * Vulnerabilities are not part of the workflow — the new version starts
 * with an empty CVE list by design.
 */
final class SoftwareSuccessorServiceTest extends AbstractWebTestCase
{
    public function testHappyPathMigrateAllAssetGroups(): void
    {
        $fx = $this->buildFixtures();
        $service = $this->service();

        $successor = $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], '2.0'),
            [
                $fx['agA']->getId() => SoftwareSuccessorService::AG_MIGRATE,
                $fx['agB']->getId() => SoftwareSuccessorService::AG_MIGRATE,
            ],
            PredecessorAction::Inactive,
        );

        $this->em()->refresh($fx['software']);
        $this->em()->refresh($successor);

        self::assertSame($fx['software'], $successor->getPredecessor());
        self::assertNotNull($fx['software']->getSupersededAt());
        self::assertFalse($fx['software']->isActive());

        self::assertCount(0, $fx['software']->getAssetGroups());
        self::assertCount(2, $successor->getAssetGroups());
    }

    public function testBothChoiceLeavesAgOnBothRows(): void
    {
        $fx = $this->buildFixtures();
        $service = $this->service();

        $successor = $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], '2.0'),
            [
                $fx['agA']->getId() => SoftwareSuccessorService::AG_BOTH,
                $fx['agB']->getId() => SoftwareSuccessorService::AG_KEEP,
            ],
            PredecessorAction::Active,
        );

        $this->em()->refresh($fx['software']);
        $this->em()->refresh($successor);

        self::assertCount(2, $fx['software']->getAssetGroups());
        self::assertCount(1, $successor->getAssetGroups());
        self::assertTrue($fx['software']->isActive());
        self::assertNotNull($fx['software']->getSupersededAt());
    }

    public function testKeepLeavesAgOnPredecessorOnly(): void
    {
        $fx = $this->buildFixtures();
        $service = $this->service();

        $successor = $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], '2.0'),
            [
                $fx['agA']->getId() => SoftwareSuccessorService::AG_KEEP,
                $fx['agB']->getId() => SoftwareSuccessorService::AG_KEEP,
            ],
            PredecessorAction::Active,
        );

        $this->em()->refresh($fx['software']);
        $this->em()->refresh($successor);

        self::assertCount(2, $fx['software']->getAssetGroups());
        self::assertCount(0, $successor->getAssetGroups());
    }

    public function testEolActionSetsActiveFalseAndEolDate(): void
    {
        $fx = $this->buildFixtures();
        self::assertNull($fx['software']->getEolDate());
        $service = $this->service();

        $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], '2.0'),
            [
                $fx['agA']->getId() => SoftwareSuccessorService::AG_MIGRATE,
                $fx['agB']->getId() => SoftwareSuccessorService::AG_MIGRATE,
            ],
            PredecessorAction::Eol,
        );

        $this->em()->refresh($fx['software']);
        self::assertFalse($fx['software']->isActive());
        self::assertNotNull($fx['software']->getEolDate());
        self::assertNotNull($fx['software']->getSupersededAt());
    }

    public function testDuplicateVersionThrowsDomainException(): void
    {
        $fx = $this->buildFixtures();
        $service = $this->service();

        $this->expectException(SoftwareSuccessorAlreadyExistsException::class);

        $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], $fx['software']->getVersion()),
            [],
            PredecessorAction::Active,
        );
    }

    public function testNewVersionStartsWithoutVulnerabilities(): void
    {
        $fx = $this->buildFixtures();
        $service = $this->service();

        $successor = $service->createSuccessor(
            $fx['software'],
            $this->draft($fx['software'], '2.0'),
            [
                $fx['agA']->getId() => SoftwareSuccessorService::AG_MIGRATE,
                $fx['agB']->getId() => SoftwareSuccessorService::AG_MIGRATE,
            ],
            PredecessorAction::Inactive,
        );

        $this->em()->refresh($successor);
        self::assertCount(0, $successor->getVulnerabilities(), 'Successor must start with no vulnerabilities; CVE re-evaluation is a separate flow.');
    }

    /**
     * @return array{
     *     software: Software,
     *     vendor: Vendor,
     *     agA: AssetGroup,
     *     agB: AssetGroup,
     * }
     */
    private function buildFixtures(): array
    {
        $em = $this->em();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $assetType = new AssetType();
        $assetType->setName('Server');
        $assetType->setActive(true);
        $em->persist($assetType);

        $agA = $this->assetGroup('AG-A', $assetType);
        $agB = $this->assetGroup('AG-B', $assetType);
        $em->persist($agA);
        $em->persist($agB);

        $software = new Software();
        $software->setName('Postgres');
        $software->setProductKey('postgres');
        $software->setVersion('1.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->addAssetGroup($agA);
        $software->addAssetGroup($agB);
        $em->persist($software);

        $em->flush();

        return [
            'software' => $software,
            'vendor' => $vendor,
            'agA' => $agA,
            'agB' => $agB,
        ];
    }

    private function draft(Software $source, string $version): Software
    {
        $draft = new Software();
        $draft->setName($source->getName());
        $draft->setProductKey($source->getProductKey());
        $draft->setVersion($version);
        $draft->setVendor($source->getVendor());
        $draft->setActive(true);

        return $draft;
    }

    private function assetGroup(string $name, AssetType $type): AssetGroup
    {
        $g = new AssetGroup();
        $g->setName($name);
        $g->setAssetType($type);
        $g->setActive(true);
        $g->setCriticality(AssetCriticality::Medium);
        $g->setExposure(AssetExposure::Intranet);
        $g->setDataSensitivity(DataSensitivity::Internal);

        return $g;
    }

    private function service(): SoftwareSuccessorService
    {
        // Private in the test container (CLAUDE.md "Testing"); instantiate manually.
        return new SoftwareSuccessorService($this->em());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
