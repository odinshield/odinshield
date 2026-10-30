<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * #65 (Q6): the asset-group list shows the three classification axes and sorts by them.
 * Without the columns, nobody could tell from the list how far the inventory is classified —
 * while those exact three values are the basis of every effective-risk score in the application.
 */
class AssetGroupListClassificationTest extends AbstractWebTestCase
{
    public function testListRendersClassificationBadges(): void
    {
        $this->loginAsAdmin();
        $this->seedGroups();

        $this->client->request('GET', '/asset-group');
        $this->assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Critical', $body);
        $this->assertStringContainsString('Internet', $body);
        $this->assertStringContainsString('Restricted', $body);
    }

    /**
     * Sorting has to follow the enum weight, not the stored string. Ordered alphabetically and
     * descending, the three rows below would come back medium, low, critical — so a test that
     * only checked "some order changed" would pass on the broken behaviour too.
     */
    public function testSortsByEnumWeightNotAlphabetically(): void
    {
        $this->loginAsAdmin();
        $this->seedGroups();

        $crawler = $this->client->request('GET', '/asset-group?sort=criticality&direction=desc');
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Crit Group', 'Mid Group', 'Low Group'], $this->nameColumnValues($crawler));

        $crawler = $this->client->request('GET', '/asset-group?sort=criticality&direction=asc');
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Low Group', 'Mid Group', 'Crit Group'], $this->nameColumnValues($crawler));
    }

    private function seedGroups(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetType = new AssetType();
        $assetType->setName('Server');
        $assetType->setActive(true);
        $em->persist($assetType);

        $rows = [
            ['Low Group', AssetCriticality::Low, AssetExposure::Isolated, DataSensitivity::Public],
            ['Crit Group', AssetCriticality::Critical, AssetExposure::Internet, DataSensitivity::Restricted],
            ['Mid Group', AssetCriticality::Medium, AssetExposure::Intranet, DataSensitivity::Internal],
        ];

        foreach ($rows as [$name, $criticality, $exposure, $sensitivity]) {
            $group = new AssetGroup();
            $group->setName($name);
            $group->setAssetType($assetType);
            $group->setActive(true);
            $group->setCriticality($criticality);
            $group->setExposure($exposure);
            $group->setDataSensitivity($sensitivity);
            $em->persist($group);
        }

        $em->flush();
    }

    /**
     * @return list<string>
     */
    private function nameColumnValues(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr')->each(
            static fn (Crawler $row): string => trim($row->filter('td')->first()->text())
        );
    }
}
