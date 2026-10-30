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
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class RoleAccessTest extends AbstractWebTestCase
{
    // ── Vulnerability: Hardware-Owner ───────────────────────────────────────

    public function testHardwareOwnerCanViewVulnerability(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareOwner']);
        $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid());
        $this->assertResponseIsSuccessful();
    }

    public function testHardwareOwnerCannotEditVulnerability(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareOwner']);
        $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid().'/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testHardwareOwnerCannotCreateVulnerability(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareOwner']);
        $this->client->request('GET', '/vulnerability/new');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── Vulnerability: Hardware-Responsible ────────────────────────────────

    public function testHardwareResponsibleCanViewVulnerabilityOnOwnAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/vulnerability/'.$fx['vulnOwn']->getUuid());
        $this->assertResponseIsSuccessful();
    }

    public function testHardwareResponsibleCannotViewVulnerabilityOnForeignAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testHardwareResponsibleCannotEditVulnerability(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/vulnerability/'.$fx['vulnOwn']->getUuid().'/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── Vulnerability: Software-Responsible ────────────────────────────────

    public function testSoftwareResponsibleCanEditVulnerabilityOnOwnSoftware(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/vulnerability/'.$fx['vulnOwn']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
    }

    // ── Vulnerability: Responsible users ───────────────────────────────────

    public function testVulnerabilityDirectResponsibleCanEdit(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['vulnDirectResponsible']);
        $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
    }

    public function testUserGroupMemberCanEditVulnerabilityOnLinkedAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['userGroupMember']);
        $this->client->request('GET', '/vulnerability/'.$fx['vulnOwn']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
    }

    // ── Vulnerability: bare user ────────────────────────────────────────────

    public function testBareUserCannotViewVulnerability(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['bareUser']);
        $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid());
        $this->assertResponseStatusCodeSame(403);
    }

    // ── Vulnerability: Closed status gate ──────────────────────────────────

    public function testIsbSeesClosedStatusOption(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['isb']);
        $crawler = $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
        $statusValues = $crawler->filter('select[name="vulnerability[status]"] option')
            ->each(static fn ($node) => $node->attr('value'));
        $this->assertContains(VulnerabilityStatus::Closed->value, $statusValues);
    }

    /**
     * #185: the option stays listed (so a closed finding still shows as closed) but cannot be picked.
     */
    public function testSoftwareOwnerCannotSelectClosedStatusOption(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareOwner']);
        $crawler = $this->client->request('GET', '/vulnerability/'.$fx['vuln']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
        $closed = $crawler->filter('select[name="vulnerability[status]"] option[value="'.VulnerabilityStatus::Closed->value.'"]');
        $this->assertSame('disabled', $closed->attr('disabled'));
    }

    // ── Software: Software-Responsible ─────────────────────────────────────

    public function testSoftwareResponsibleCanViewOwnSoftware(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/software/'.$fx['ownSoftware']->getUuid());
        $this->assertResponseIsSuccessful();
    }

    public function testSoftwareResponsibleCanEditOwnSoftware(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/software/'.$fx['ownSoftware']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
    }

    public function testSoftwareResponsibleCannotCreateNewSoftware(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/software/new');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSoftwareResponsibleCanAccessSuccessorForm(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/software/'.$fx['ownSoftware']->getUuid().'/successor/new');
        $this->assertResponseIsSuccessful();
    }

    public function testSoftwareResponsibleCannotEditForeignSoftware(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareResponsible']);
        $this->client->request('GET', '/software/'.$fx['foreignSoftware']->getUuid().'/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── AssetGroup: Hardware-Responsible ───────────────────────────────────

    public function testHardwareResponsibleCanEditOwnAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/asset-group/'.$fx['ownAssetGroup']->getUuid().'/edit');
        $this->assertResponseIsSuccessful();
    }

    public function testHardwareResponsibleCannotEditForeignAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/asset-group/'.$fx['foreignAssetGroup']->getUuid().'/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testHardwareResponsibleCannotCreateAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareResponsible']);
        $this->client->request('GET', '/asset-group/new');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── AssetGroup: Software-Owner ──────────────────────────────────────────

    public function testSoftwareOwnerCanViewAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareOwner']);
        $this->client->request('GET', '/asset-group/'.$fx['ownAssetGroup']->getUuid());
        $this->assertResponseIsSuccessful();
    }

    public function testSoftwareOwnerCannotEditAssetGroup(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareOwner']);
        $this->client->request('GET', '/asset-group/'.$fx['ownAssetGroup']->getUuid().'/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── AssetType: role gates ───────────────────────────────────────────────

    public function testSoftwareOwnerCannotAccessAssetTypeIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareOwner']);
        $this->client->request('GET', '/asset-type');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testIsbCanAccessAssetTypeIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['isb']);
        $this->client->request('GET', '/asset-type');
        $this->assertResponseIsSuccessful();
    }

    public function testHardwareOwnerCanAccessAssetTypeIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['hardwareOwner']);
        $this->client->request('GET', '/asset-type');
        $this->assertResponseIsSuccessful();
    }

    // ── Vendor: role gates ─────────────────────────────────────────────────

    public function testSoftwareOwnerCanAccessVendorIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['softwareOwner']);
        $this->client->request('GET', '/vendor');
        $this->assertResponseIsSuccessful();
    }

    public function testIsbCanAccessVendorIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['isb']);
        $this->client->request('GET', '/vendor');
        $this->assertResponseIsSuccessful();
    }

    public function testBareUserCannotAccessVendorIndex(): void
    {
        $fx = $this->buildFixtures();
        $this->client->loginUser($fx['bareUser']);
        $this->client->request('GET', '/vendor');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     isb: User,
     *     hardwareOwner: User,
     *     hardwareResponsible: User,
     *     softwareOwner: User,
     *     softwareResponsible: User,
     *     vulnDirectResponsible: User,
     *     userGroupMember: User,
     *     bareUser: User,
     *     ownAssetGroup: AssetGroup,
     *     foreignAssetGroup: AssetGroup,
     *     ownSoftware: Software,
     *     foreignSoftware: Software,
     *     vuln: Vulnerability,
     *     vulnOwn: Vulnerability
     * }
     */
    private function buildFixtures(): array
    {
        $em = $this->em();

        $vendor = new Vendor();
        $vendor->setName('Fixtures Co');
        $vendor->setVendorKey('fixtures-co');
        $vendor->setActive(true);
        $em->persist($vendor);

        $isb = $this->makeUser('isb-role', [User::ROLE_ISB]);
        $hardwareOwner = $this->makeUser('hw-owner', [User::ROLE_HARDWARE_OWNER]);
        $hardwareResponsible = $this->makeUser('hw-responsible', [User::ROLE_USER]);
        $softwareOwner = $this->makeUser('sw-owner', [User::ROLE_SOFTWARE_OWNER]);
        $softwareResponsible = $this->makeUser('sw-responsible', [User::ROLE_USER]);
        $vulnDirectResponsible = $this->makeUser('vuln-responsible', [User::ROLE_USER]);
        $userGroupMember = $this->makeUser('ug-member', [User::ROLE_USER]);
        $bareUser = $this->makeUser('bare', [User::ROLE_USER]);

        foreach ([$isb, $hardwareOwner, $hardwareResponsible, $softwareOwner, $softwareResponsible, $vulnDirectResponsible, $userGroupMember, $bareUser] as $u) {
            $em->persist($u);
        }

        $assetType = new AssetType();
        $assetType->setName('Server');
        $assetType->setActive(true);
        $em->persist($assetType);

        $ownAssetGroup = $this->makeAssetGroup('Own AG', $assetType);
        $ownAssetGroup->setResponsibleUser($hardwareResponsible);
        $em->persist($ownAssetGroup);

        $foreignAssetGroup = $this->makeAssetGroup('Foreign AG', $assetType);
        $em->persist($foreignAssetGroup);

        $ownSoftware = $this->makeSoftware('Own Software', '1.0', $vendor);
        $ownSoftware->setResponsibleUser($softwareResponsible);
        $ownAssetGroup->addSoftware($ownSoftware);
        $em->persist($ownSoftware);

        $foreignSoftware = $this->makeSoftware('Foreign Software', '2.0', $vendor);
        $em->persist($foreignSoftware);

        $userGroup = new UserGroup();
        $userGroup->setName('Own Team');
        $userGroupMember->addUserGroup($userGroup);
        $assetType->addUserGroup($userGroup);
        $em->persist($userGroup);

        $em->flush();

        // vuln: on foreignAssetGroup; direct responsible user; used for bypass-role and direct-responsible tests
        $vuln = $this->makeVulnerability('Test Vuln');
        $vuln->setResponsibleUser($vulnDirectResponsible);
        $vuln->addSoftware($foreignSoftware);
        $this->addOccurrence($vuln, $foreignAssetGroup);
        $em->persist($vuln);

        // vulnOwn: on ownAssetGroup and ownSoftware; used for scoped-access tests
        $vulnOwn = $this->makeVulnerability('Own Vuln');
        $vulnOwn->addSoftware($ownSoftware);
        $this->addOccurrence($vulnOwn, $ownAssetGroup);
        $em->persist($vulnOwn);

        $em->flush();

        return [
            'isb' => $isb,
            'hardwareOwner' => $hardwareOwner,
            'hardwareResponsible' => $hardwareResponsible,
            'softwareOwner' => $softwareOwner,
            'softwareResponsible' => $softwareResponsible,
            'vulnDirectResponsible' => $vulnDirectResponsible,
            'userGroupMember' => $userGroupMember,
            'bareUser' => $bareUser,
            'ownAssetGroup' => $ownAssetGroup,
            'foreignAssetGroup' => $foreignAssetGroup,
            'ownSoftware' => $ownSoftware,
            'foreignSoftware' => $foreignSoftware,
            'vuln' => $vuln,
            'vulnOwn' => $vulnOwn,
        ];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** @param list<string> $roles */
    private function makeUser(string $username, array $roles): User
    {
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName(ucfirst($username));
        $user->setLastName('Persona');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles($roles);

        return $user;
    }

    private function makeSoftware(string $name, string $version, Vendor $vendor): Software
    {
        $software = new Software();
        $software->setName($name);
        $software->setProductKey(strtolower(str_replace(' ', '-', $name)));
        $software->setVersion($version);
        $software->setVendor($vendor);
        $software->setActive(true);

        return $software;
    }

    private function makeAssetGroup(string $name, AssetType $type): AssetGroup
    {
        $group = new AssetGroup();
        $group->setName($name);
        $group->setAssetType($type);
        $group->setActive(true);

        return $group;
    }

    private function makeVulnerability(string $title): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setTitle($title);
        $vuln->setSeverity(VulnerabilitySeverity::Medium);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable());

        return $vuln;
    }

    private function addOccurrence(Vulnerability $vuln, AssetGroup $assetGroup): void
    {
        $occurrence = new VulnerabilityOccurrence();
        $occurrence->setVulnerability($vuln);
        $occurrence->setAssetGroup($assetGroup);
        $vuln->addOccurrence($occurrence);
    }
}
