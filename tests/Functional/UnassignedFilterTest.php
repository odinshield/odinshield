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
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issue #66 — "what is nobody responsible for?" must be answerable.
 *
 * Responsibility is the organising idea of the tool, yet `responsibleUser` only ever appeared as
 * a positive equality in queries; an unowned object could only be found by opening every record
 * one by one. These tests pin the three lists, the fallback of the filter value, and the
 * dashboard hint that makes the gap visible without being asked.
 */
final class UnassignedFilterTest extends AbstractWebTestCase
{
    public function testAssetGroupListFiltersToUnassigned(): void
    {
        $user = $this->loginAsAdmin();
        $this->createAssetGroupNamed('Owned Group', $user);
        $this->createAssetGroupNamed('Orphan Group', null);

        $this->client->request('GET', '/asset-group?assignee=unassigned');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Orphan Group', $body);
        self::assertStringNotContainsString('Owned Group', $body);
    }

    public function testSoftwareListFiltersToUnassignedAndShowsTheColumn(): void
    {
        $user = $this->loginAsAdmin();
        $this->createSoftwareNamed('Owned Product', $user);
        $this->createSoftwareNamed('Orphan Product', null);

        $this->client->request('GET', '/software?assignee=unassigned');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Orphan Product', $body);
        self::assertStringNotContainsString('Owned Product', $body);

        // The list had no "responsible user" column at all before #66 — without it the filter
        // would answer the question without ever showing the answer.
        $this->client->request('GET', '/software');
        $all = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Without responsible user', $all);
        self::assertStringContainsString($user->getFullName() ?? '', $all);
    }

    public function testVulnerabilityListFiltersToUnassigned(): void
    {
        $user = $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroupNamed('Group For Vulns', $user);
        $this->createVulnerabilityNamed('Owned Finding', $assetGroup, $user);
        $this->createVulnerabilityNamed('Orphan Finding', $assetGroup, null);

        $this->client->request('GET', '/vulnerability?assignee=unassigned');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Orphan Finding', $body);
        self::assertStringNotContainsString('Owned Finding', $body);
    }

    /**
     * The filter value must be explicit: a typo in the URL falls back to "all" and must never
     * produce the unassigned view by accident.
     */
    public function testUnknownOrEmptyValueFallsBackToAll(): void
    {
        $user = $this->loginAsAdmin();
        $this->createAssetGroupNamed('Owned Group', $user);
        $this->createAssetGroupNamed('Orphan Group', null);

        foreach (['?assignee=nonsense', '?assignee=', ''] as $query) {
            $this->client->request('GET', '/asset-group'.$query);
            $body = (string) $this->client->getResponse()->getContent();

            self::assertStringContainsString('Orphan Group', $body, sprintf('Query "%s" should list everything.', $query));
            self::assertStringContainsString('Owned Group', $body, sprintf('Query "%s" should list everything.', $query));
        }
    }

    public function testDashboardHintsAtUnassignedObjectsAndLinksToTheFilter(): void
    {
        $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroupNamed('Orphan Group', null);
        $this->createSoftwareNamed('Orphan Product', null);
        $this->createVulnerabilityNamed('Orphan Finding', $assetGroup, null);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href*="assignee=unassigned"]')->count(),
            'The hint must link into the filter, not just state a number.',
        );
        self::assertStringContainsString('without a responsible user', (string) $this->client->getResponse()->getContent());

        // Sanity: the three links point at the three lists.
        foreach (['/vulnerability', '/software', '/asset-group'] as $path) {
            self::assertGreaterThan(
                0,
                $crawler->filter(sprintf('a[href^="%s?"][href*="assignee=unassigned"]', $path))->count(),
                sprintf('Missing link to %s.', $path),
            );
        }
    }

    /**
     * No leftover "0 objects" banner once everything is assigned.
     */
    public function testDashboardHintDisappearsWhenEverythingIsAssigned(): void
    {
        $user = $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroupNamed('Owned Group', $user);
        $this->createSoftwareNamed('Owned Product', $user);
        $this->createVulnerabilityNamed('Owned Finding', $assetGroup, $user);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('without a responsible user', (string) $this->client->getResponse()->getContent());
    }

    /**
     * An administrator is not an ISB. Measured while building this: with the hint bound to
     * "ISB or admin", an admin without inventory roles saw "18 objects" and every link ended in
     * "no records for the current filters", offering "reset filters" as the one action that
     * could not help — a global figure must never link into an unexplained empty list (#67).
     */
    public function testAdminWithoutInventoryRolesGetsNoHint(): void
    {
        $this->loginAsAdminOnly();
        $assetGroup = $this->createAssetGroupNamed('Orphan Group', null);
        $this->createSoftwareNamed('Orphan Product', null);
        $this->createVulnerabilityNamed('Orphan Finding', $assetGroup, null);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('without a responsible user', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Decided on 2026-09-11: the hint is the ISB's. An owner works within their own scope; the
     * organisation-wide coverage gap is not their statement to read off the dashboard.
     */
    public function testSoftwareOwnerGetsNoHint(): void
    {
        $this->loginAsSoftwareOwner();
        $this->createSoftwareNamed('Orphan Product', null);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('without a responsible user', (string) $this->client->getResponse()->getContent());
    }

    private function loginAsAdminOnly(): User
    {
        $em = $this->em();

        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('adminonly');
        $user->setFirstName('Admin');
        $user->setLastName('Only');
        $user->setEmail('adminonly@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_ADMIN]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    private function createAssetGroupNamed(string $name, ?User $responsible): AssetGroup
    {
        $em = $this->em();

        $assetType = $em->getRepository(AssetType::class)->findOneBy(['name' => 'Systems']);

        if (!$assetType instanceof AssetType) {
            $assetType = new AssetType();
            $assetType->setName('Systems');
            $assetType->setActive(true);
            $em->persist($assetType);
        }

        $assetGroup = new AssetGroup();
        $assetGroup->setName($name);
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $assetGroup->setResponsibleUser($responsible);
        $em->persist($assetGroup);
        $em->flush();

        return $assetGroup;
    }

    private function createSoftwareNamed(string $name, ?User $responsible): Software
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
        $software->setProductKey(strtolower(str_replace(' ', '-', $name)));
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $software->setResponsibleUser($responsible);
        $em->persist($software);
        $em->flush();

        return $software;
    }

    private function createVulnerabilityNamed(string $title, AssetGroup $assetGroup, ?User $responsible): Vulnerability
    {
        $em = $this->em();

        $vuln = new Vulnerability();
        $vuln->setTitle($title);
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->addAssetGroup($assetGroup);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $vuln->setResponsibleUser($responsible);
        $em->persist($vuln);
        $em->flush();

        return $vuln;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
