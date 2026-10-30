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
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Enum\MaintenanceSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * List filters survive the round trip through detail, edit and review actions (#216).
 *
 * Whoever follows the "objects without a responsible user" banner (#66) works through a list
 * filtered by `assignee=unassigned`. Every link and redirect out of that list must carry the
 * filter back, the empty state must name it, and "mark as reviewed" only exists where a review
 * cycle exists (Manual maintenance).
 */
final class ListReturnParamsTest extends AbstractWebTestCase
{
    public function testAssetGroupListLinksCarryTheAssigneeFilter(): void
    {
        $this->loginAsAdmin();
        $this->assetGroup('Orphan', null);

        $crawler = $this->client->request('GET', '/asset-group?assignee=unassigned');

        self::assertResponseIsSuccessful();
        $hrefs = $crawler->filter('#new-asset-group-link, a[href*="/asset-group/"]')->each(
            static fn (Crawler $a): string => (string) $a->attr('href'),
        );
        self::assertGreaterThanOrEqual(3, \count($hrefs), 'New, show and edit links expected.');
        foreach ($hrefs as $href) {
            self::assertStringContainsString('assignee=unassigned', $href);
        }
    }

    public function testEditFromFilteredAssetGroupListReturnsWithTheFilter(): void
    {
        $this->loginAsAdmin();
        $group = $this->assetGroup('Orphan', null);

        // Follow the edit link the filtered list renders — building the URL by hand would skip
        // exactly the step that lost the filter.
        $list = $this->client->request('GET', '/asset-group?assignee=unassigned&sort=name&direction=desc');
        $editHref = $list->filter(\sprintf('a[href^="/asset-group/%s/edit"]', $group->getUuid()))->attr('href');
        self::assertNotNull($editHref);

        $crawler = $this->client->request('GET', $editHref);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="asset_group"]')->form());

        self::assertResponseStatusCodeSame(303);
        $query = $this->redirectQuery();
        self::assertSame('unassigned', $query['assignee'] ?? null);
        self::assertSame('desc', $query['direction'] ?? null);
    }

    public function testSoftwareNewLinkCarriesTheAssigneeFilter(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/software?assignee=unassigned');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('assignee=unassigned', (string) $crawler->filter('#new-software-link')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assigneeFilteredLists(): iterable
    {
        yield 'asset groups' => ['/asset-group'];
        yield 'software' => ['/software'];
    }

    #[DataProvider('assigneeFilteredLists')]
    public function testEmptyListUnderAssigneeFilterSaysSoAndOffersReset(string $path): void
    {
        $user = $this->loginAsAdmin();
        // Only assigned rows exist: the unfiltered list is not empty, the filtered one is.
        $this->assetGroup('Owned', $user);
        $this->software('Owned', $user, MaintenanceSource::Manual);

        $crawler = $this->client->request('GET', $path.'?assignee=unassigned');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'Every entry has a responsible user.');
        self::assertCount(1, $crawler->filter(\sprintf('.card a[href="%s"]', $path)), 'Reset link expected.');

        // Counter-check: without the filter the rows are listed, no empty state.
        $this->client->request('GET', $path);
        self::assertSelectorTextNotContains('.card', 'Every entry has a responsible user.');
    }

    public function testMarkReviewedKeepsTheListFilters(): void
    {
        $this->loginAsAdmin();
        $software = $this->software('Manual One', null, MaintenanceSource::Manual);

        $crawler = $this->client->request('GET', '/software?assignee=unassigned&sort=version&direction=desc&limit=25');
        $form = $crawler->filter($this->markReviewedSelector($software));
        self::assertCount(1, $form);
        $this->client->submit($form->form());

        self::assertResponseRedirects();
        $query = $this->redirectQuery();
        self::assertSame('unassigned', $query['assignee'] ?? null);
        self::assertSame('version', $query['sort'] ?? null);
        self::assertSame('desc', $query['direction'] ?? null);
        self::assertSame('25', $query['limit'] ?? null);
        self::assertNotNull($this->reload($software)->getLastReviewedAt());
    }

    public function testMarkReviewedIsOfferedOnlyForManualSoftware(): void
    {
        $this->loginAsAdmin();
        $manual = $this->software('Manual One', null, MaintenanceSource::Manual);
        $import = $this->software('Import One', null, MaintenanceSource::Import);
        $none = $this->software('None One', null, MaintenanceSource::None);

        $crawler = $this->client->request('GET', '/software');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter($this->markReviewedSelector($manual)));
        self::assertCount(0, $crawler->filter($this->markReviewedSelector($import)));
        self::assertCount(0, $crawler->filter($this->markReviewedSelector($none)));
    }

    public function testMarkReviewedIsRejectedForSoftwareThatIsNotManual(): void
    {
        $this->loginAsAdmin();
        $software = $this->software('Switched', null, MaintenanceSource::Manual);

        // Take a valid token while the button is still offered, then switch the maintenance
        // source — the server must not rely on the hidden button.
        $crawler = $this->client->request('GET', '/software');
        $form = $crawler->filter($this->markReviewedSelector($software))->form();
        $this->em()->createQuery('UPDATE '.Software::class.' s SET s.maintenanceSource = :import WHERE s.id = :id')
            ->setParameter('import', MaintenanceSource::Import)
            ->setParameter('id', $software->getId())
            ->execute();

        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertNull($this->reload($software)->getLastReviewedAt());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Only manually maintained software can be marked as reviewed.');
    }

    public function testDashboardReviewButtonGuardsAgainstDoubleSubmit(): void
    {
        $this->loginAsAdmin();
        $this->software('Overdue', null, MaintenanceSource::Manual);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('form[action$="/mark-reviewed"] button[type="submit"]');
        self::assertCount(1, $button);
        self::assertSame('form-submit', $button->attr('data-controller'));
    }

    public function testDetailPagesAreTitledWithTheObjectName(): void
    {
        $this->loginAsAdmin();
        $em = $this->em();

        $vendor = new Vendor();
        $vendor->setName('Acme Networks');
        $vendor->setVendorKey('acme_networks');
        $vendor->setActive(true);
        $em->persist($vendor);

        $userGroup = new UserGroup();
        $userGroup->setName('Night Shift');
        $em->persist($userGroup);
        $em->flush();

        $assetType = $this->assetGroup('Any', null)->getAssetType();

        foreach ([
            '/vendor/'.$vendor->getUuid() => 'Acme Networks',
            '/asset-type/'.$assetType->getUuid() => 'Systems',
            '/user-group/'.$userGroup->getUuid() => 'Night Shift',
        ] as $path => $name) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextSame('h1.page-title', $name);
            self::assertPageTitleSame($name.' - OdinShield');
        }
    }

    private function markReviewedSelector(Software $software): string
    {
        return \sprintf('form[action="/software/%s/mark-reviewed"]', $software->getUuid());
    }

    /**
     * @return array<int|string, mixed>
     */
    private function redirectQuery(): array
    {
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return $query;
    }

    private function assetGroup(string $name, ?User $responsible): AssetGroup
    {
        $em = $this->em();
        $assetType = $em->getRepository(AssetType::class)->findOneBy(['name' => 'Systems']);
        if (!$assetType instanceof AssetType) {
            $assetType = new AssetType();
            $assetType->setName('Systems');
            $assetType->setActive(true);
            $em->persist($assetType);
        }

        $group = new AssetGroup();
        $group->setName($name);
        $group->setAssetType($assetType);
        $group->setActive(true);
        $group->setResponsibleUser($responsible);
        $em->persist($group);
        $em->flush();

        return $group;
    }

    private function software(string $name, ?User $responsible, MaintenanceSource $source): Software
    {
        $em = $this->em();
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['vendorKey' => 'acme']);
        if (!$vendor instanceof Vendor) {
            $vendor = new Vendor();
            $vendor->setName('Acme');
            $vendor->setVendorKey('acme');
            $vendor->setActive(true);
            $em->persist($vendor);
        }

        $software = new Software();
        $software->setName($name);
        $software->setVendor($vendor);
        $software->setProductKey(strtolower(str_replace(' ', '_', $name)));
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $software->setMaintenanceSource($source);
        $software->setResponsibleUser($responsible);
        $em->persist($software);
        $em->flush();

        return $software;
    }

    private function reload(Software $software): Software
    {
        $em = $this->em();
        $em->clear();
        $fresh = $em->getRepository(Software::class)->findOneBy(['uuid' => $software->getUuid()]);
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
