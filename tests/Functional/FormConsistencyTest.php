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
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Issue #215: form consistency — help switch, the collapsible section hint, the position of the
 * responsibilities warning and the live "justification required" marking.
 *
 * The hint/error order itself is checked on the template sources ({@see \App\Tests\Unit\Templates\FormHintOrderTest}).
 * What the marking does on change is JavaScript and was checked in the browser; here the
 * server-rendered starting state and the wiring are pinned, because a wrong one fails silently.
 */
final class FormConsistencyTest extends AbstractWebTestCase
{
    public function testVendorFormCarriesTheHelpSwitch(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/vendor/new');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-form-help-target="hint"]'), 'Vendor key and aliases carry a hint.');
        self::assertCount(1, $crawler->filter('[data-form-help-target="switch"]'), 'Two hints or more → the show-everything switch.');
    }

    public function testAssetGroupSectionHintIsCollapsible(): void
    {
        $this->loginAsHardwareOwner();

        $crawler = $this->client->request('GET', '/asset-group/new');

        self::assertResponseIsSuccessful();
        $hint = $crawler->filter('#help_asset_group_risk_properties');
        self::assertCount(1, $hint);
        self::assertSame('hint', $hint->attr('data-form-help-target'), 'A plain form-text cannot be collapsed.');
        self::assertCount(1, $crawler->filter('[data-form-help-target="toggle"][aria-controls="help_asset_group_risk_properties"]'));
    }

    public function testResponsibilityWarningSitsAboveTheActiveSwitch(): void
    {
        $user = $this->createUser('responsible');
        $this->persistSoftwareFor($user);
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form #active_responsibilities_warning'), 'The warning is part of the form now.');

        $html = (string) $this->client->getResponse()->getContent();
        $warning = strpos($html, 'id="active_responsibilities_warning"');
        $switch = strpos($html, 'name="user[isActive]"');
        $submit = strpos($html, 'type="submit"', (int) $switch);
        self::assertNotFalse($warning);
        self::assertNotFalse($switch);
        self::assertNotFalse($submit);
        self::assertLessThan($switch, $warning, 'The warning stands above the switch it is about.');
        self::assertLessThan($submit, $switch, 'Both stand above Save.');

        self::assertStringContainsString(
            'active_responsibilities_warning',
            (string) $crawler->filter('input[name="user[isActive]"]')->attr('aria-describedby'),
            'Screen readers hear the warning together with the switch.',
        );
    }

    public function testWarningSurvivesAFailedSaveWithTheSwitchTurnedOff(): void
    {
        // The moment the warning matters most: the admin unticked "Active" and the save failed.
        $user = $this->createUser('responsible');
        $this->persistSoftwareFor($user);
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');
        $form = $crawler->filter('form:not([action="/logout"])')->form();
        $isActive = $form['user[isActive]'];
        self::assertInstanceOf(ChoiceFormField::class, $isActive);
        $isActive->untick();
        $form['user[email]'] = 'not-an-address';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('#active_responsibilities_warning'));
    }

    public function testNoResponsibilityWarningWithoutResponsibilities(): void
    {
        $user = $this->createUser('idle');
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#active_responsibilities_warning'));
        self::assertNull($crawler->filter('input[name="user[isActive]"]')->attr('aria-describedby'));
    }

    public function testNewUserFormHasNoResponsibilityWarning(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/new');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#active_responsibilities_warning'));
    }

    public function testOverrideMarkingIsHiddenWhileSeverityMatchesTheSource(): void
    {
        $vuln = $this->persistImportedVulnerability(VulnerabilitySeverity::High, null);
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/vulnerability/'.$vuln->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        $wired = $crawler->filter('[data-controller="severity-override"]');
        self::assertCount(1, $wired);
        self::assertSame('high', $wired->attr('data-severity-override-source-value'));
        self::assertCount(1, $wired->filter('select[name="vulnerability[severity]"][data-severity-override-target="severity"]'));
        self::assertCount(1, $wired->filter('textarea[name="vulnerability[severityOverrideReason]"][data-severity-override-target="reason"]'));

        self::assertNotNull($crawler->filter('[data-severity-override-target="marker"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-severity-override-target="notice"]')->attr('hidden'));
        self::assertNull($crawler->filter('textarea[name="vulnerability[severityOverrideReason]"]')->attr('aria-required'));

        // The live region stays rendered and visible; only its child toggles, or screen readers miss the change.
        $region = $crawler->filter('#severity_override_notice');
        self::assertSame('polite', $region->attr('aria-live'));
        self::assertNull($region->attr('hidden'));
        self::assertStringContainsString(
            'severity_override_notice',
            (string) $crawler->filter('textarea[name="vulnerability[severityOverrideReason]"]')->attr('aria-describedby'),
        );
    }

    public function testOverrideMarkingIsShownForADeviatingSeverity(): void
    {
        $vuln = $this->persistImportedVulnerability(VulnerabilitySeverity::Low, 'Only reachable from the admin VLAN.');
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/vulnerability/'.$vuln->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-severity-override-target="marker"]')->attr('hidden'));
        $notice = $crawler->filter('[data-severity-override-target="notice"]');
        self::assertNull($notice->attr('hidden'));
        self::assertStringContainsString('differs from the source value', $notice->text());
        self::assertSame('true', $crawler->filter('textarea[name="vulnerability[severityOverrideReason]"]')->attr('aria-required'));
    }

    public function testManualRowIsNotWired(): void
    {
        $vuln = $this->persistVulnerability();
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/vulnerability/'.$vuln->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="severity-override"]'), 'No source value, nothing to deviate from.');
        self::assertCount(0, $crawler->filter('[data-severity-override-target]'));
    }

    private function persistImportedVulnerability(VulnerabilitySeverity $severity, ?string $reason): Vulnerability
    {
        $vuln = $this->persistVulnerability();
        $vuln->setSourceSeverity(VulnerabilitySeverity::High);
        $vuln->setSeverity($severity);
        $vuln->setSeverityOverrideReason($reason);
        $this->em()->flush();

        return $vuln;
    }

    private function persistVulnerability(): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setTitle('Form consistency vulnerability');
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->addAssetGroup($this->createAssetGroup());
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($vuln);
        $this->em()->flush();

        return $vuln;
    }

    private function persistSoftwareFor(User $user): void
    {
        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $this->em()->persist($vendor);

        $software = new Software();
        $software->setName('OwnedApp');
        $software->setProductKey('owned_app');
        $software->setVersion('1.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->setResponsibleUser($user);
        $this->em()->persist($software);

        $this->em()->flush();
    }

    private function createUser(string $username): User
    {
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Test');
        $user->setLastName('Form');
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

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
