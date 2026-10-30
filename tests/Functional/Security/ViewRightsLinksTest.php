<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #201 — software and asset-group names are links only for a viewer who may open the target;
 * everyone else sees plain text instead of a link into a 403.
 * #202 — the asset-group lookup behind the vulnerability form answers everyone who may fill it.
 */
final class ViewRightsLinksTest extends AbstractWebTestCase
{
    public function testResponsibleUserWithoutRolesSeesSoftwareAndAssetGroupAsText(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['reporter']);

        $crawler = $this->client->request('GET', '/vulnerability');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Postgres 1.0', $crawler->filter('td.collapsible-software')->text());
        self::assertCount(0, $crawler->filter('a[href="/software/'.$fx['software']->getUuid().'"]'));

        $crawler = $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Postgres 1.0');
        self::assertSelectorTextContains('body', 'AG-A');
        self::assertCount(0, $crawler->filter('a[href="/software/'.$fx['software']->getUuid().'"]'));
        self::assertCount(0, $crawler->filter('a[href="/asset-group/'.$fx['assetGroup']->getUuid().'"]'));
    }

    public function testSoftwareOwnerStillSeesLinks(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($this->loginAsSoftwareOwner());

        $crawler = $this->client->request('GET', '/vulnerability');
        self::assertCount(1, $crawler->filter('a[href="/software/'.$fx['software']->getUuid().'"]'));

        $crawler = $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid());
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/software/'.$fx['software']->getUuid().'"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/asset-group/'.$fx['assetGroup']->getUuid().'"]')->count());
    }

    public function testAssetGroupLookupAnswersAUserWithoutSoftwareRights(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['reporter']);

        $this->client->request('GET', '/vulnerability/software/'.$fx['software']->getUuid().'/asset-groups');

        self::assertResponseIsSuccessful();
        $groups = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($groups);
        self::assertSame(['AG-A'], array_column($groups, 'name'));
    }

    /**
     * The issue expected a 403 here; measured otherwise: SoftwareVoter lets a Hardware-Owner view
     * software, so the lookup has always answered them. Pinned so the gate stays deliberate.
     */
    public function testAssetGroupLookupAnswersAHardwareOwner(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAsHardwareOwner();

        $this->client->request('GET', '/vulnerability/software/'.$fx['software']->getUuid().'/asset-groups');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{reporter: User, software: Software, assetGroup: AssetGroup, vuln: Vulnerability}
     */
    private function buildFixtures(): array
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $reporter = new User();
        $reporter->setUsername('reporter201');
        $reporter->setFirstName('Re');
        $reporter->setLastName('Porter');
        $reporter->setEmail('reporter201@example.com');
        $reporter->setPassword('unused');
        $reporter->setIsActive(true);
        $reporter->setLocale('en');
        $reporter->setMustChangePassword(false);
        $reporter->setRoles([]);
        $em->persist($reporter);

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $assetType = new AssetType();
        $assetType->setName('Server');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('AG-A');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $em->persist($assetGroup);

        $software = new Software();
        $software->setName('Postgres');
        $software->setProductKey('postgres');
        $software->setVersion('1.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->addAssetGroup($assetGroup);
        $em->persist($software);

        $vuln = new Vulnerability();
        $vuln->setTitle('Reported by a user without roles');
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $vuln->setResponsibleUser($reporter);
        $vuln->addSoftware($software);
        $vuln->addAssetGroup($assetGroup);
        $em->persist($vuln);

        $em->flush();

        return ['reporter' => $reporter, 'software' => $software, 'assetGroup' => $assetGroup, 'vuln' => $vuln];
    }
}
