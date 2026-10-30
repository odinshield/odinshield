<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use App\Enum\MaintenanceSource;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;

class BsiAlignmentTest extends AbstractWebTestCase
{
    public function testEffectiveRiskScoreIsProductOfWeights(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetType = new AssetType();
        $assetType->setName('Endpoint');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('Crown-Jewel Portal');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $assetGroup->setCriticality(AssetCriticality::Critical);   // weight 4
        $assetGroup->setExposure(AssetExposure::Internet);          // weight 3
        $assetGroup->setDataSensitivity(DataSensitivity::Restricted); // weight 4
        $em->persist($assetGroup);

        $vuln = new Vulnerability();
        $vuln->setTitle('Critical exploit in portal');
        $vuln->setSeverity(VulnerabilitySeverity::High);           // weight 4
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-02-01'));
        $vuln->addAssetGroup($assetGroup);
        $em->persist($vuln);
        $em->flush();

        $em->clear();

        $reloaded = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($reloaded);
        // 4 × 4 × 3 × 4 = 192 (effective-risk band: Critical).
        $this->assertSame(192, $reloaded->getEffectiveRisk());
        $this->assertSame('critical', $reloaded->getEffectiveRiskBand()->value);

        // Occurrence got its denormalised score populated by the listener.
        $occurrence = $reloaded->getOccurrences()->first();
        $this->assertNotFalse($occurrence);
        $this->assertSame(192, $occurrence->getEffectiveRiskScore());
    }

    public function testActiveExploitationDoublesEffectiveRisk(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetGroup = $this->persistAssetGroup($em, AssetCriticality::Medium, AssetExposure::Intranet, DataSensitivity::Internal); // 2 × 2 × 2

        $vuln = new Vulnerability();
        $vuln->setTitle('Exploited in the wild');
        $vuln->setSeverity(VulnerabilitySeverity::High);           // weight 4
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-02-01'));
        $vuln->setIsActivelyExploited(true);
        $vuln->addAssetGroup($assetGroup);
        $em->persist($vuln);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($reloaded);
        // #125: 4 × 2 × 2 × 2 = 32 (Medium), doubled to 64 (High).
        $this->assertSame(64, $reloaded->getEffectiveRisk());
        $this->assertSame('high', $reloaded->getEffectiveRiskBand()->value);
    }

    public function testEffectiveRiskRecomputesWhenExploitationFlagChanges(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetGroup = $this->persistAssetGroup($em, AssetCriticality::Medium, AssetExposure::Intranet, DataSensitivity::Internal); // 2 × 2 × 2

        $vuln = new Vulnerability();
        $vuln->setTitle('Later found in KEV');
        $vuln->setSeverity(VulnerabilitySeverity::High);           // weight 4
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-02-01'));
        $vuln->addAssetGroup($assetGroup);
        $em->persist($vuln);
        $em->flush();

        $this->assertSame(32, $vuln->getEffectiveRisk());

        // Cross-entity cascade: only the vulnerability changes, the occurrence row must follow.
        $vuln->setIsActivelyExploited(true);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($reloaded);
        $this->assertSame(64, $reloaded->getEffectiveRisk());

        $reloaded->setIsActivelyExploited(false);
        $em->flush();
        $em->clear();

        $unflagged = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($unflagged);
        $this->assertSame(32, $unflagged->getEffectiveRisk());
    }

    public function testEffectiveRiskRecomputesWhenAssetGroupChanges(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetType = new AssetType();
        $assetType->setName('Endpoint');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('Internal App');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $assetGroup->setCriticality(AssetCriticality::Low);         // weight 1
        $assetGroup->setExposure(AssetExposure::Isolated);           // weight 1
        $assetGroup->setDataSensitivity(DataSensitivity::Public);    // weight 1
        $em->persist($assetGroup);

        $vuln = new Vulnerability();
        $vuln->setTitle('Medium issue');
        $vuln->setSeverity(VulnerabilitySeverity::Medium);           // weight 3
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-02-01'));
        $vuln->addAssetGroup($assetGroup);
        $em->persist($vuln);
        $em->flush();

        $this->assertSame(3, $vuln->getEffectiveRisk());

        // Flip criticality to Critical and verify listener recomputed.
        $assetGroup->setCriticality(AssetCriticality::Critical);     // weight 4
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($reloaded);
        // 3 × 4 × 1 × 1 = 12.
        $this->assertSame(12, $reloaded->getEffectiveRisk());
    }

    public function testVulnerabilityIndexSortByEffectiveRisk(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetType = new AssetType();
        $assetType->setName('Endpoint');
        $assetType->setActive(true);
        $em->persist($assetType);

        $hot = new AssetGroup();
        $hot->setName('A-Internet-Facing');
        $hot->setAssetType($assetType);
        $hot->setActive(true);
        $hot->setCriticality(AssetCriticality::Critical);
        $hot->setExposure(AssetExposure::Internet);
        $hot->setDataSensitivity(DataSensitivity::Restricted);
        $em->persist($hot);

        $cold = new AssetGroup();
        $cold->setName('B-Isolated-Lab');
        $cold->setAssetType($assetType);
        $cold->setActive(true);
        $cold->setCriticality(AssetCriticality::Low);
        $cold->setExposure(AssetExposure::Isolated);
        $cold->setDataSensitivity(DataSensitivity::Public);
        $em->persist($cold);

        $low = new Vulnerability();
        $low->setTitle('AAA Low-risk finding');
        $low->setSeverity(VulnerabilitySeverity::Informational);
        $low->setStatus(VulnerabilityStatus::Open);
        $low->setSource(VulnerabilitySource::Manual);
        $low->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $low->addAssetGroup($cold);
        $em->persist($low);

        $high = new Vulnerability();
        $high->setTitle('ZZZ Critical exploit');
        $high->setSeverity(VulnerabilitySeverity::Critical);
        $high->setStatus(VulnerabilityStatus::Open);
        $high->setSource(VulnerabilitySource::Manual);
        $high->setDiscoveredAt(new \DateTimeImmutable('2026-01-02'));
        $high->addAssetGroup($hot);
        $em->persist($high);

        $em->flush();

        // Default sort is effectiveRisk desc — high-risk must appear first despite alphabetical ordering.
        $crawler = $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();

        $rows = $crawler->filter('tbody tr');
        $firstRowText = $rows->first()->text();
        $this->assertStringContainsString('Critical exploit', $firstRowText);
    }

    public function testMarkReviewedActionBumpsLastReviewedAt(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Widget Server');
        $software->setProductKey('widget_server');
        $software->setVersion('1.0.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->setMaintenanceSource(MaintenanceSource::Manual);
        $em->persist($software);
        $em->flush();

        $this->assertNull($software->getLastReviewedAt());

        // Fetch the dashboard which renders a mark-reviewed form for this software,
        // then extract the CSRF token embedded in the HTML.
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/software/'.$software->getUuid().'/mark-reviewed"]')->first();
        $this->assertNotSame(0, $form->count(), 'Mark-reviewed form not found on dashboard');
        $token = $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/software/'.$software->getUuid().'/mark-reviewed', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(302);

        $em->clear();
        $reloaded = $em->getRepository(Software::class)->findOneBy(['uuid' => $software->getUuid()]);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->getLastReviewedAt());
    }

    public function testMarkReviewedRejectsMissingCsrf(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Widget Server');
        $software->setProductKey('widget_server');
        $software->setVersion('1.0.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $em->persist($software);
        $em->flush();

        $this->client->request('POST', '/software/'.$software->getUuid().'/mark-reviewed');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsEosAndReviewWidgets(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $eosSoon = new Software();
        $eosSoon->setName('LegacyApp');
        $eosSoon->setProductKey('legacy_app');
        $eosSoon->setVersion('2.0');
        $eosSoon->setVendor($vendor);
        $eosSoon->setActive(true);
        $eosSoon->setEolDate((new \DateTimeImmutable())->modify('+30 days'));
        $em->persist($eosSoon);

        $reviewOverdue = new Software();
        $reviewOverdue->setName('StaleManualApp');
        $reviewOverdue->setProductKey('stale_manual_app');
        $reviewOverdue->setVersion('1.0');
        $reviewOverdue->setVendor($vendor);
        $reviewOverdue->setActive(true);
        $reviewOverdue->setMaintenanceSource(MaintenanceSource::Manual);
        // lastReviewedAt stays null → qualifies as stale.
        $em->persist($reviewOverdue);

        $em->flush();

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $pageText = $crawler->text();
        $this->assertStringContainsString('LegacyApp', $pageText);
        $this->assertStringContainsString('StaleManualApp', $pageText);
    }

    private function persistAssetGroup(EntityManagerInterface $em, AssetCriticality $criticality, AssetExposure $exposure, DataSensitivity $sensitivity): AssetGroup
    {
        $assetType = new AssetType();
        $assetType->setName('Endpoint');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('Exploitation test group');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $assetGroup->setCriticality($criticality);
        $assetGroup->setExposure($exposure);
        $assetGroup->setDataSensitivity($sensitivity);
        $em->persist($assetGroup);

        return $assetGroup;
    }
}
