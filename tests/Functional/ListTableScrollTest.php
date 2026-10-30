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
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A table wider than its card scrolls inside the card, never the whole page (#254).
 *
 * Five lists put their table straight into `div.card-table`, which only scrolled below 768 px, so
 * the page grew sideways on a 1,280 px laptop. `.card-table` now scrolls at every width (app.css)
 * and is the one wrapper all lists use — no `.table-responsive`, no inline `overflow-x` (#217
 * item 3). Filter bars wrap instead of pushing the card header past the window.
 */
final class ListTableScrollTest extends AbstractWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function listPages(): iterable
    {
        yield 'vulnerabilities' => ['/vulnerability'];
        yield 'software' => ['/software'];
        yield 'asset groups' => ['/asset-group'];
        yield 'asset types' => ['/asset-type'];
        yield 'vendors' => ['/vendor'];
        yield 'users' => ['/user'];
        yield 'user groups' => ['/user-group'];
        yield 'audit' => ['/audit'];
    }

    #[DataProvider('listPages')]
    public function testTableScrollsInsideTheCardTableWrapper(string $path): void
    {
        $this->loginAsAdmin();
        $this->seedOneRowPerList();
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $tables = $crawler->filter('.card table');
        self::assertGreaterThan(0, $tables->count(), 'Seed data must render a table, or the check below proves nothing.');
        self::assertCount($tables->count(), $crawler->filter('.card div.card-table table'));
        self::assertCount(0, $crawler->filter('.card .table-responsive'));
        self::assertCount(0, $crawler->filter('.card-table[style]'));
    }

    #[DataProvider('listPages')]
    public function testFilterBarWraps(string $path): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.card-header .d-flex')->reduce(
            static fn ($row): bool => $row->children()->count() > 1,
        );
        self::assertGreaterThan(0, $rows->count());
        $rows->each(static function ($row) use ($path): void {
            self::assertStringContainsString('flex-wrap', (string) $row->attr('class'), $path);
        });
    }

    private function seedOneRowPerList(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Widget');
        $software->setVendor($vendor);
        $software->setProductKey('widget');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $em->persist($software);

        $assetType = new AssetType();
        $assetType->setName('Systems');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('Servers');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $em->persist($assetGroup);

        $userGroup = new UserGroup();
        $userGroup->setName('Operators');
        $em->persist($userGroup);

        $vulnerability = new Vulnerability();
        $vulnerability->setTitle('Scroll check');
        $vulnerability->setSeverity(VulnerabilitySeverity::High);
        $vulnerability->setStatus(VulnerabilityStatus::Open);
        $vulnerability->setSource(VulnerabilitySource::Manual);
        $vulnerability->addAssetGroup($assetGroup);
        $vulnerability->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $em->persist($vulnerability);

        $em->flush();
    }
}
