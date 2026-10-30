<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\Software;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\MaintenanceSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issue #299: the software name in the EOS and review-overdue widgets links to the detail page
 * exactly when that page would not answer 403 — `SOFTWARE_VIEW`, the gate of
 * `SoftwareController::show`. The mark-reviewed button stays behind `SOFTWARE_MODIFY`.
 *
 * Every test follows the rule "link visible ⇔ target opens": a rendered link is followed; where
 * the link is missing although the entry is listed, the detail page is requested directly and
 * must be refused. The button gate itself is unchanged by #299.
 */
final class DashboardSoftwareLinkTest extends AbstractWebTestCase
{
    public function testHardwareOwnerGetsTheLinkButNoReviewButton(): void
    {
        $software = $this->persistFlaggedSoftware();
        $this->loginAsHardwareOwner();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter($this->linkSelector($software)), 'Name must link in both widgets.');
        self::assertCount(0, $crawler->filter($this->reviewFormSelector($software)), 'Hardware owners are read-only.');
        $this->assertLinkOpens($software);
    }

    public function testSoftwareOwnerKeepsLinkAndReviewButton(): void
    {
        $software = $this->persistFlaggedSoftware();
        $this->loginAsSoftwareOwner();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter($this->linkSelector($software)));
        self::assertCount(1, $crawler->filter($this->reviewFormSelector($software)));
        $this->assertLinkOpens($software);
    }

    public function testResponsibleUserWithoutRoleKeepsLinkAndReviewButton(): void
    {
        $user = $this->createPlainUser('swresponsible');
        $software = $this->persistFlaggedSoftware($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter($this->linkSelector($software)));
        self::assertCount(1, $crawler->filter($this->reviewFormSelector($software)));
        $this->assertLinkOpens($software);
    }

    public function testAssetGroupResponsibleSeesTheEntryWithoutLink(): void
    {
        // Responsible for an asset group = global software viewer in the widgets, but
        // SOFTWARE_VIEW only on their own software. A link here would end in 403.
        $user = $this->createPlainUser('agresponsible');
        $assetGroup = $this->createAssetGroup();
        $assetGroup->setResponsibleUser($user);
        $software = $this->persistFlaggedSoftware();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('FlaggedApp', $crawler->text(), 'The entry itself stays visible.');
        self::assertCount(0, $crawler->filter($this->linkSelector($software)));
        self::assertCount(0, $crawler->filter($this->reviewFormSelector($software)));

        $this->client->request('GET', '/software/'.$software->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Qualifies for both widgets: end of support within the warning window and a manually
     * maintained product that was never reviewed.
     */
    private function persistFlaggedSoftware(?User $responsible = null): Software
    {
        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $this->em()->persist($vendor);

        $software = new Software();
        $software->setName('FlaggedApp');
        $software->setProductKey('flagged_app');
        $software->setVersion('1.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->setEolDate((new \DateTimeImmutable())->modify('+30 days'));
        $software->setMaintenanceSource(MaintenanceSource::Manual);
        $software->setResponsibleUser($responsible);
        $this->em()->persist($software);

        $this->em()->flush();

        return $software;
    }

    private function createPlainUser(string $username): User
    {
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Test');
        $user->setLastName('Plain');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([]);

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function assertLinkOpens(Software $software): void
    {
        $link = $this->client->getCrawler()->filter($this->linkSelector($software))->first()->link();
        $this->client->click($link);

        self::assertResponseIsSuccessful();
    }

    private function linkSelector(Software $software): string
    {
        return sprintf('a[href="/software/%s"]', $software->getUuid());
    }

    private function reviewFormSelector(Software $software): string
    {
        return sprintf('form[action="/software/%s/mark-reviewed"]', $software->getUuid());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
