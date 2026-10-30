<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\CsafProviderState;
use App\Entity\User;
use App\Enum\CsafProviderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issue #63: the ISB learns *that* his advisory sources are down, without gaining access to the
 * admin's import operations.
 *
 * The trigger was a real 74-day outage on Demo that nobody noticed although the auto-pause mail
 * had been sent — one mail gets lost, a dashboard warning does not.
 *
 * The role split is the load-bearing part and mirrors {@see ImportStatusControllerTest}: the
 * banner is *added* for ROLE_ISB while `/import-status` stays ROLE_ADMIN-only. A failure in
 * testIsbStillHasNoAccessToImportStatus() means someone widened that gate.
 *
 * Issue #299: the banner links to `/import-status` for exactly the users that page admits
 * (ROLE_ADMIN) — the ISB keeps the banner without a link.
 */
final class DashboardSourceHealthTest extends AbstractWebTestCase
{
    public function testIsbSeesTheWarningWithCountAndAge(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-74 days'));
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $alert = $crawler->filter('.alert-warning');
        self::assertCount(1, $alert, 'The ISB must be warned without having to look for it.');
        self::assertStringContainsString('1 advisory source is disrupted', $alert->text());
        self::assertStringContainsString('74 days', $alert->text());
    }

    public function testWarningNamesNeitherProviderNorError(): void
    {
        // The whole point of the split: the ISB gets the fachliche statement, not the plumbing.
        $this->persistDisruptedProvider('secret-provider-key', new \DateTimeImmutable('-3 days'), 'Idle timeout reached for https://example.test');
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        $alert = $crawler->filter('.alert-warning')->text();
        self::assertStringNotContainsString('secret-provider-key', $alert);
        self::assertStringNotContainsString('Idle timeout', $alert);
        self::assertStringNotContainsString('/import-status', $this->client->getResponse()->getContent() ?: '');
    }

    public function testPluralAndOldestOutageWin(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-5 days'));
        $this->persistDisruptedProvider('prov-b', new \DateTimeImmutable('-40 days'));
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        $alert = $crawler->filter('.alert-warning')->text();
        self::assertStringContainsString('2 advisory sources are disrupted', $alert);
        self::assertStringContainsString('40 days', $alert, 'The longest-running outage sets the age.');
    }

    public function testNeverSucceededSourceGetsItsOwnWording(): void
    {
        // "broken since 0 days" would be a lie for a source that never delivered anything.
        $this->persistDisruptedProvider('never-ran', null);
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('have ever been retrieved', $crawler->filter('.alert-warning')->text());
    }

    public function testNoWarningWhenEverySourceIsHealthy(): void
    {
        $state = new CsafProviderState('healthy', 'Healthy', 'https://example.test/pm.json');
        $state->setLastSuccessAt(new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($state);
        $this->em()->flush();
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('.alert-warning'));
    }

    public function testDisabledSourceIsNotReportedAsDisrupted(): void
    {
        // Huawei on Demo: access-restricted, switched off on purpose. Reporting it would produce
        // a warning nobody can ever clear.
        $state = new CsafProviderState('switched-off', 'Switched Off', 'https://example.test/pm.json');
        $state->setStatus(CsafProviderStatus::Disabled);
        $state->setLastSuccessAt(new \DateTimeImmutable('-200 days'));
        $this->em()->persist($state);
        $this->em()->flush();
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('.alert-warning'));
    }

    public function testUserWithoutIsbOrAdminSeesNothing(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-74 days'));
        $this->loginAsSoftwareOwner();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.alert-warning'));
    }

    public function testAdminSeesTheWarningToo(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-2 days'));
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.alert-warning'));
    }

    public function testAdminGetsALinkToImportStatusThatOpens(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-2 days'));
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/');

        $link = $crawler->filter('.alert-warning a[href="/import-status"]');
        self::assertCount(1, $link, 'The admin is the one who repairs — the banner must lead there.');

        $this->client->click($link->link());
        self::assertResponseIsSuccessful();
    }

    public function testPureAdminGetsTheLinkToo(): void
    {
        // loginAsAdmin() carries every role; this one carries ROLE_ADMIN alone, so a gate bound
        // to any other role would fail here.
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-2 days'));
        $this->loginWithRoles('testpureadmin', [User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.alert-warning a[href="/import-status"]'));
    }

    public function testIsbGetsTheWarningWithoutLink(): void
    {
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-2 days'));
        $this->loginAsIsb();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.alert-warning'));
        self::assertCount(0, $crawler->filter('.alert-warning a'), 'The ISB may not open /import-status — no link into a 403.');
    }

    public function testIsbStillHasNoAccessToImportStatus(): void
    {
        // Guards the decision from issue #43 that this change deliberately does NOT touch.
        $this->persistDisruptedProvider('prov-a', new \DateTimeImmutable('-74 days'));
        $this->loginAsIsb();

        $this->client->request('GET', '/import-status');

        self::assertResponseStatusCodeSame(403);
    }

    private function persistDisruptedProvider(string $key, ?\DateTimeImmutable $lastSuccess, string $error = 'boom'): void
    {
        $state = new CsafProviderState($key, 'Provider '.$key, 'https://example.test/'.$key.'.json');
        $state->setStatus(CsafProviderStatus::AutoPaused);
        $state->setLastSuccessAt($lastSuccess);
        $state->setLastErrorAt(new \DateTimeImmutable());
        $state->setLastErrorMessage($error);
        $state->setConsecutiveFailures(3);

        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function loginAsIsb(): User
    {
        return $this->loginWithRoles('testisb', [User::ROLE_ISB]);
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(string $username, array $roles): User
    {
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles($roles);

        $this->em()->persist($user);
        $this->em()->flush();

        $this->client->loginUser($user);

        return $user;
    }
}
