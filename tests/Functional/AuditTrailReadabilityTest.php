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
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\AssetCriticality;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issue #64: the audit trail as evidence — readable object names, translated field names and
 * enum values, and the CSV export. Complements AuditControllerTest, which covers access and
 * filtering.
 *
 * The logged-in test user's locale is `en`, so the assertions below use the English catalogue.
 */
final class AuditTrailReadabilityTest extends AbstractWebTestCase
{
    public function testObjectColumnShowsTheNameAndLinksToTheDetailPage(): void
    {
        $assetGroup = $this->createAssetGroup();
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter(sprintf('table a[href="/asset-group/%s"]', $assetGroup->getUuid()));
        self::assertGreaterThan(0, $link->count(), 'The object column must link to the detail page.');
        self::assertSame('Test Asset Group', trim($link->first()->text()));
    }

    public function testDeletedObjectKeepsItsIdAndDoesNotLink(): void
    {
        $assetGroup = $this->createAssetGroup();
        $id = $assetGroup->getId();
        $uuid = (string) $assetGroup->getUuid();

        $em = $this->em();
        $em->remove($assetGroup);
        $em->flush();

        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter(sprintf('table a[href="/asset-group/%s"]', $uuid))->count());
        self::assertStringContainsString('#'.$id, (string) $this->client->getResponse()->getContent());

        // A CSV cell carries no badge, so the marker has to be spelled out — otherwise a deleted
        // object is indistinguishable from an unnamed one in the hand-over file.
        $csv = $this->fetchCsv('/audit?entity_type=asset_group');
        self::assertStringContainsString(sprintf('"#%d (Deleted)"', $id), $csv);
    }

    public function testFieldNamesAndEnumValuesAreTranslated(): void
    {
        $assetGroup = $this->createAssetGroup();
        $em = $this->em();
        $assetGroup->setCriticality(AssetCriticality::Critical);
        $em->flush();

        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group&action=update');

        self::assertResponseIsSuccessful();
        self::assertSame('Business criticality:', trim($crawler->filter('.timeline-diff-field')->first()->text()));
        // The raw backing values are `medium` / `critical`; the labels must be shown instead.
        self::assertSame('Medium → Critical', preg_replace('/\s+/', ' ', trim($crawler->filter('.timeline-diff-value')->first()->text())));
    }

    /**
     * The acceptance criterion of issue #64: label resolution is bundled per entity type, so a
     * page ten times as long must not cost ten times the queries.
     */
    public function testQueryCountDoesNotGrowWithPageSize(): void
    {
        $this->loginAsAdmin();
        $this->seedAssetGroups(30);

        // The Doctrine collector accumulates everything logged since the last profiled request —
        // including the seeding above. Burn one measurement so the two compared ones are clean.
        $this->countQueries('/audit?entity_type=asset_group&limit=10');

        $small = $this->countQueries('/audit?entity_type=asset_group&limit=10');
        $large = $this->countQueries('/audit?entity_type=asset_group&limit=25');

        self::assertSame($small, $large, sprintf('10 rows cost %d queries, 25 rows cost %d.', $small, $large));
    }

    public function testCsvExportHonoursTheFiltersAndWritesOneRowPerField(): void
    {
        $assetGroup = $this->createAssetGroup();
        $em = $this->em();
        $assetGroup->setCriticality(AssetCriticality::Critical);
        $em->flush();

        $this->loginAsAdmin();

        $csv = $this->fetchCsv('/audit?entity_type=asset_group&action=update');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\u{FEFF}", $csv, 'Excel needs the byte-order mark to read UTF-8.');
        self::assertStringContainsString('Time;"Object type";Object;"Object ID";Action;Field;Old;New;"Changed by"', $csv);
        // One row per changed field, values resolved exactly as on screen.
        self::assertStringContainsString('"Test Asset Group";1;Updated;"Business criticality";Medium;Critical', $csv);
        // The action filter must carry over: no insert rows in the file.
        self::assertStringNotContainsString(';Created;', $csv);
    }

    public function testCsvExportIsAdminOnly(): void
    {
        $this->client->request('GET', '/audit/export');
        self::assertResponseRedirects('/login');

        $this->loginAsSoftwareOwner();
        $this->client->request('GET', '/audit/export');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * `_audit_entry.html.twig` is the shared renderer of the history tab on seven detail pages
     * and of the global timeline's expandable rows — a change there reaches all of them. This
     * walks every audited entity type through the per-entity endpoint that renders it.
     */
    public function testHistoryTabRendersForEveryAuditedEntityType(): void
    {
        $admin = $this->loginAsAdmin();
        $em = $this->em();

        $assetGroup = $this->createAssetGroup();

        $userGroup = new UserGroup();
        $userGroup->setName('Audit Group');
        $em->persist($userGroup);

        $vendor = new Vendor();
        $vendor->setName('Audit Vendor');
        $vendor->setVendorKey('audit_vendor');
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Audit Software');
        $software->setProductKey('audit_software');
        $software->setVersion('1.0.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $em->persist($software);

        $vulnerability = new Vulnerability();
        $vulnerability->setTitle('Audit Vulnerability');
        $vulnerability->setSeverity(VulnerabilitySeverity::High);
        $vulnerability->setStatus(VulnerabilityStatus::Open);
        $vulnerability->setSource(VulnerabilitySource::Manual);
        $vulnerability->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $vulnerability->addAssetGroup($assetGroup);
        $em->persist($vulnerability);

        $em->flush();

        $targets = [
            'user' => $admin->getUuid(),
            'user_group' => $userGroup->getUuid(),
            'vendor' => $vendor->getUuid(),
            'asset_type' => $assetGroup->getAssetType()->getUuid(),
            'asset_group' => $assetGroup->getUuid(),
            'software' => $software->getUuid(),
            'vulnerability' => $vulnerability->getUuid(),
        ];

        foreach ($targets as $type => $uuid) {
            $this->client->request('GET', sprintf('/audit/%s/%s/timeline', $type, $uuid));

            self::assertResponseIsSuccessful(sprintf('History tab of "%s" must render.', $type));
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('audit.field.', $content, sprintf('Untranslated field key leaked into "%s".', $type));
        }
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * All of them share one asset type, so every row of the pages under test is an asset group —
     * otherwise a longer page would legitimately touch one more entity type.
     */
    private function seedAssetGroups(int $count): void
    {
        $em = $this->em();

        $assetType = new AssetType();
        $assetType->setName('Bulk Type');
        $assetType->setActive(true);
        $em->persist($assetType);
        $em->flush();

        for ($i = 0; $i < $count; ++$i) {
            $group = new AssetGroup();
            $group->setName('Bulk Group '.$i);
            $group->setAssetType($assetType);
            $group->setActive(true);
            $em->persist($group);
        }
        $em->flush();
    }

    private function countQueries(string $uri): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'The profiler must be enabled for this request.');

        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $collector */
        $collector = $profile->getCollector('db');

        return $collector->getQueryCount();
    }

    /**
     * Follows the export button so the test also proves the link carries the active filters.
     * The export is a StreamedResponse: the browser captures its body into the BrowserKit
     * response, while `Response::getContent()` of a streamed response is `false`.
     */
    private function fetchCsv(string $listUri): string
    {
        $crawler = $this->client->request('GET', $listUri);
        $href = $crawler->filter('a[href*="/audit/export"]')->attr('href');

        $this->client->request('GET', (string) $href);

        return $this->client->getInternalResponse()->getContent();
    }
}
