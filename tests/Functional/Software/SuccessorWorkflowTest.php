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
use App\Entity\User;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end tests for the successor workflow at the HTTP layer.
 * Exercises the controller, form binding, service dispatch, and final redirect.
 */
final class SuccessorWorkflowTest extends AbstractWebTestCase
{
    public function testGetFormRendersForRoleSoftwareOwner(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);

        $crawler = $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');

        self::assertResponseIsSuccessful();
        $prefix = $this->formPrefix($crawler);

        self::assertGreaterThan(0, $crawler->filter('input[name="'.$prefix.'[version]"]')->count());
        // Two AG choice rows.
        self::assertGreaterThan(0, $crawler->filter('input[name="'.$prefix.'[ag_choice_'.$fx['agA']->getId().']"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="'.$prefix.'[ag_choice_'.$fx['agB']->getId().']"]')->count());
    }

    public function testPostHappyPathCreatesSuccessorAndMigratesAssetGroups(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);

        $crawler = $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');
        $prefix = $this->formPrefix($crawler);
        $form = $crawler->filter('form:not([action="/logout"])')->form();

        $form[$prefix.'[version]'] = '2.0';
        $form[$prefix.'[ag_choice_'.$fx['agA']->getId().']'] = 'migrate';
        $form[$prefix.'[ag_choice_'.$fx['agB']->getId().']'] = 'migrate';
        $form[$prefix.'[predecessor_action]'] = 'inactive';

        $this->client->submit($form);
        // #214: the redirect carries the (here: default) list context.
        self::assertResponseRedirects();
        self::assertStringStartsWith('/software/'.$fx['software']->getUuid()->toRfc4122().'?', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->clear();
        $predecessorAfter = $em->getRepository(Software::class)->findOneBy(['version' => '1.0']);
        $successor = $em->getRepository(Software::class)->findOneBy(['version' => '2.0']);

        self::assertNotNull($successor);
        self::assertNotNull($predecessorAfter);
        self::assertSame($predecessorAfter->getId(), $successor->getPredecessor()?->getId());
        self::assertFalse($predecessorAfter->isActive());
        self::assertNotNull($predecessorAfter->getSupersededAt());
        self::assertCount(0, $predecessorAfter->getAssetGroups());
        self::assertCount(2, $successor->getAssetGroups());
    }

    public function testPostDuplicateVersionShowsFormError(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);

        $crawler = $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');
        $prefix = $this->formPrefix($crawler);
        $form = $crawler->filter('form:not([action="/logout"])')->form();

        // Same version as the predecessor — must be rejected by the unique constraint.
        $form[$prefix.'[version]'] = '1.0';
        $form[$prefix.'[ag_choice_'.$fx['agA']->getId().']'] = 'migrate';
        $form[$prefix.'[ag_choice_'.$fx['agB']->getId().']'] = 'migrate';
        $form[$prefix.'[predecessor_action]'] = 'inactive';

        $this->client->submit($form);
        // Re-renders form with an error (Symfony emits 422 for invalid submits) —
        // either from UniqueEntity (preferred) or from the catch (fallback).
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->client->getResponse()->isRedirection());

        $em = $this->em();
        $em->clear();
        $count = $em->getRepository(Software::class)->count(['version' => '1.0']);
        self::assertSame(1, $count, 'No second row with the duplicate version may exist.');
    }

    /**
     * #214: after a validation error the page must come back with the user's own choices, and
     * tell the defaults controller not to overwrite them (it used to flip "keep predecessor
     * active" back to "inactive", so the second submit deactivated the old version).
     */
    public function testValidationErrorKeepsTheChosenPredecessorAction(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);

        $crawler = $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');
        $prefix = $this->formPrefix($crawler);
        self::assertSame('false', $crawler->filter('[data-controller~="successor-defaults"]')->attr('data-successor-defaults-submitted-value'));

        $form = $crawler->filter('form:not([action="/logout"])')->form();
        $form[$prefix.'[version]'] = '1.0';
        $form[$prefix.'[ag_choice_'.$fx['agA']->getId().']'] = 'migrate';
        $form[$prefix.'[ag_choice_'.$fx['agB']->getId().']'] = 'migrate';
        $form[$prefix.'[predecessor_action]'] = 'active';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('[data-controller~="successor-defaults"]')->attr('data-successor-defaults-submitted-value'));
        self::assertNotNull($crawler->filter('input[name="'.$prefix.'[predecessor_action]"][value="active"]')->attr('checked'));
        self::assertNull($crawler->filter('input[name="'.$prefix.'[predecessor_action]"][value="inactive"]')->attr('checked'));
        self::assertSelectorTextContains('.alert-danger', $this->translated('software.vendor_product_version.already_used', 'validators'));
    }

    /**
     * #214: errors without a field of their own (here: a failed CSRF check) must be shown, not
     * swallowed by the hand-rendered radios.
     */
    public function testFormLevelErrorIsShown(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);

        $crawler = $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');
        $prefix = $this->formPrefix($crawler);
        $values = $crawler->filter('form:not([action="/logout"])')->form()->getPhpValues();
        $values[$prefix]['version'] = '2.0';
        $values[$prefix]['_token'] = 'forged';
        $this->client->request('POST', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor', $values, [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form .alert-danger[role="alert"]');
    }

    /**
     * #214: the list context (search, sort, page …) survives the round trip like on every other
     * software form — into the form action, the back/cancel links and the redirect after saving.
     */
    public function testListContextSurvivesTheRoundTrip(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['admin']);
        $uuid = $fx['software']->getUuid()->toRfc4122();

        $crawler = $this->client->request('GET', '/software/'.$uuid.'/successor/new?search=acme&page=2&sort=version&direction=desc');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form:not([action="/logout"])')->form();
        self::assertStringContainsString('search=acme', $form->getUri());
        self::assertStringContainsString('page=2', $form->getUri());
        foreach ($crawler->filter('a[href^="/software/'.$uuid.'"]') as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertStringContainsString('search=acme', $link->getAttribute('href'));
        }

        $prefix = $this->formPrefix($crawler);
        $form[$prefix.'[version]'] = '2.0';
        $this->client->submit($form);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/software/'.$uuid.'?', $location);
        self::assertStringContainsString('search=acme', $location);
        self::assertStringContainsString('sort=version', $location);
    }

    public function testBareUserIsForbidden(): void
    {
        $fx = $this->buildFixtures();
        $this->loginAs($fx['bare']);

        $this->client->request('GET', '/software/'.$fx['software']->getUuid()->toRfc4122().'/successor/new');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{
     *     admin: User,
     *     bare: User,
     *     software: Software,
     *     agA: AssetGroup,
     *     agB: AssetGroup,
     *     vuln: Vulnerability,
     * }
     */
    private function buildFixtures(): array
    {
        $em = $this->em();
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

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

        $vuln = new Vulnerability();
        $vuln->setTitle('CVE-x');
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable());
        $vuln->addSoftware($software);

        foreach ([$agA, $agB] as $ag) {
            $occ = new VulnerabilityOccurrence($vuln, $ag);
            $occ->setStatus(VulnerabilityStatus::Open);
            $vuln->addOccurrence($occ);
        }
        $em->persist($vuln);

        $admin = $this->user('admin', $hasher, [User::ROLE_SOFTWARE_OWNER, User::ROLE_HARDWARE_OWNER]);
        $bare = $this->user('bare', $hasher, [User::ROLE_USER]);
        $em->persist($admin);
        $em->persist($bare);

        $em->flush();

        return [
            'admin' => $admin,
            'bare' => $bare,
            'software' => $software,
            'agA' => $agA,
            'agB' => $agB,
            'vuln' => $vuln,
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $username, \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setFirstName(ucfirst($username));
        $user->setLastName('User');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles($roles);

        return $user;
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

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    /**
     * Resolve the form name prefix (e.g. "software_successor") by reading the
     * first hidden _token field — Symfony emits one per form and uses the form
     * name as the bracketed parent key.
     */
    private function formPrefix(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $name = $crawler->filter('form:not([action="/logout"]) input[type="hidden"]')->first()->attr('name') ?? '';
        if (1 === preg_match('/^([^\[]+)\[/', $name, $matches)) {
            return $matches[1];
        }

        // Fallback: assume the version field is mapped without prefix.
        return '';
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function translated(string $key, string $domain = 'messages'): string
    {
        /** @var \Symfony\Contracts\Translation\TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');

        return $translator->trans($key, [], $domain, 'en');
    }
}
