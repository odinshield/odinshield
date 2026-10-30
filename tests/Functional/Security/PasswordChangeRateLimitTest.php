<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * SEC-006 — `password_change_limiter` caps per-user password-change traffic
 * at 5 submissions per sliding hour; on `/settings` every current-password
 * re-check counts, failed ones included (#193). The same limiter guards
 * `/settings` (re-auth path) and `/change-password` (forced-rotation path)
 * so neither becomes a brute-force vehicle for the current-password check
 * or a hash-cost amplifier under a logged-in attacker.
 */
final class PasswordChangeRateLimitTest extends AbstractWebTestCase
{
    public function testForcedRotationShowsFriendlyMessageOnSixthAttempt(): void
    {
        $user = $this->createUser('sec006rot', 'Start0000!', mustChangePassword: true);
        $this->resetLimiter($user->getUserIdentifier());
        $this->client->loginUser($user);

        for ($i = 1; $i <= 5; ++$i) {
            $this->markMustChangePassword($user->getUserIdentifier());
            // #188: a successful change logs out for real, so every round signs in again.
            $this->signInAgain($user);
            $this->postChangePassword('Rotate'.$i.'!');
            $this->assertResponseRedirects('/login', null, "Attempt #{$i} should be accepted.");
        }

        // L24: the throttled 6th attempt must not dead-end on a bare 429 page. The user stays on
        // the change-password form with a friendly, retry-aware flash message.
        $this->markMustChangePassword($user->getUserIdentifier());
        $this->signInAgain($user);
        $this->postChangePassword('Rotate6!');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Zu viele', (string) $this->client->getResponse()->getContent());
    }

    public function testSettingsPasswordChangeShowsFriendlyMessageOnSixthAttempt(): void
    {
        $user = $this->createUser('sec006set', 'Start0000!', mustChangePassword: false);
        $this->resetLimiter($user->getUserIdentifier());
        $this->client->loginUser($user);

        $current = 'Start0000!';
        for ($i = 1; $i <= 5; ++$i) {
            $next = 'Update'.$i.'!';
            $this->postSettings($user, $current, $next);
            // L27: a successful settings save returns to the settings page, not the dashboard.
            $this->assertResponseRedirects('/settings', null, "Attempt #{$i} should be accepted.");
            $current = $next;
        }

        // L24: the throttled 6th attempt re-renders the settings form with a friendly message.
        $this->postSettings($user, $current, 'Update6!');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Zu viele', (string) $this->client->getResponse()->getContent());
    }

    /**
     * #193: a wrong current password invalidates the form, and the limiter must still count it —
     * otherwise the re-check is open to brute force. Covers both re-auth triggers.
     */
    public function testWrongCurrentPasswordsAreCountedForPasswordChange(): void
    {
        $user = $this->createUser('sec006wrongpw', 'Start0000!', mustChangePassword: false);
        $this->resetLimiter($user->getUserIdentifier());
        $this->client->loginUser($user);

        for ($i = 1; $i <= 5; ++$i) {
            $this->postSettings($user, 'Wrong000'.$i.'!', 'Update'.$i.'!');
            $this->assertResponseStatusCodeSame(422, "Attempt #{$i} must be rejected by the current-password check.");
        }

        // The correct password no longer helps once the budget is spent.
        $this->postSettings($user, 'Start0000!', 'Update6!');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Zu viele', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongCurrentPasswordsAreCountedForEmailChange(): void
    {
        $user = $this->createUser('sec006wrongmail', 'Start0000!', mustChangePassword: false);
        $this->resetLimiter($user->getUserIdentifier());
        $this->client->loginUser($user);

        for ($i = 1; $i <= 5; ++$i) {
            $this->postSettings($user, 'Wrong000'.$i.'!', '', 'redirect@example.com');
            $this->assertResponseStatusCodeSame(422, "Attempt #{$i} must be rejected by the current-password check.");
        }

        $this->postSettings($user, 'Start0000!', '', 'redirect@example.com');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Zu viele', (string) $this->client->getResponse()->getContent());

        $this->getEm()->clear();
        $reloaded = $this->getEm()->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertSame('sec006wrongmail@example.com', $reloaded->getEmail(), 'A throttled attempt must not change the e-mail.');
    }

    public function testCosmeticEditDoesNotChargeLimiter(): void
    {
        $user = $this->createUser('sec006cosmetic', 'Start0000!', mustChangePassword: false);
        $this->resetLimiter($user->getUserIdentifier());
        $this->client->loginUser($user);

        for ($i = 1; $i <= 6; ++$i) {
            $this->postSettings($user, '', '');
            $this->assertResponseRedirects('/settings', null, "Cosmetic save #{$i} must not be throttled.");
        }
    }

    public function testLimiterIsKeyedPerUser(): void
    {
        $attacker = $this->createUser('sec006attacker', 'Start0000!', mustChangePassword: true);
        $bystander = $this->createUser('sec006bystander', 'Start0000!', mustChangePassword: true);
        $this->resetLimiter($attacker->getUserIdentifier());
        $this->resetLimiter($bystander->getUserIdentifier());

        // Exhaust the attacker's bucket.
        $this->client->loginUser($attacker);
        for ($i = 1; $i <= 5; ++$i) {
            $this->markMustChangePassword($attacker->getUserIdentifier());
            $this->signInAgain($attacker);
            $this->postChangePassword('Attack'.$i.'!');
            $this->assertResponseRedirects('/login');
        }
        $this->markMustChangePassword($attacker->getUserIdentifier());
        $this->signInAgain($attacker);
        $this->postChangePassword('Attack6!');
        // L24: throttled attempt now re-renders the form (200) instead of a 429 error page.
        $this->assertResponseIsSuccessful();

        // The bystander on a fresh kernel still has all five attempts available.
        $this->client->loginUser($bystander);
        $this->postChangePassword('Bystander1!');
        $this->assertResponseRedirects('/login', null, 'Bystander must not inherit attacker\'s rate-limit state.');
    }

    /**
     * Sign in with the user as currently stored — the in-memory object still carries the old
     * password hash, which User::isEqualTo() (#184) would treat as a changed account.
     */
    private function signInAgain(User $user): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($fresh);
        $this->client->loginUser($fresh);
    }

    private function postChangePassword(string $newPassword): void
    {
        // Stateless CSRF — see PasswordChangeRateLimitTest peers in this dir.
        $this->client->request(
            'POST',
            '/change-password',
            [
                'change_password' => [
                    'plainPassword' => [
                        'first' => $newPassword,
                        'second' => $newPassword,
                    ],
                    '_token' => 'csrf-token',
                ],
            ],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function postSettings(User $user, string $currentPassword, string $newPassword, ?string $email = null): void
    {
        $this->client->request(
            'POST',
            '/settings',
            [
                'user_settings' => [
                    'email' => $email ?? $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'locale' => $user->getLocale() ?? 'de',
                    'theme' => '',
                    'emailNotifications' => '1',
                    'currentPassword' => $currentPassword,
                    'plainPassword' => [
                        'first' => $newPassword,
                        'second' => $newPassword,
                    ],
                    '_token' => 'csrf-token',
                ],
            ],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function createUser(string $username, string $password, bool $mustChangePassword): User
    {
        $em = $this->getEm();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('SEC');
        $user->setLastName('Six');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setEmailNotifications(false);
        $user->setMustChangePassword($mustChangePassword);
        $user->setRoles([]);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function markMustChangePassword(string $username): void
    {
        $this->getConnection()->executeStatement(
            'UPDATE "user" SET must_change_password = TRUE WHERE username = :username',
            ['username' => $username],
        );
    }

    private function resetLimiter(string $userIdentifier): void
    {
        /** @var RateLimiterFactoryInterface $factory */
        $factory = $this->client->getContainer()->get('limiter.password_change_limiter');
        $factory->create($userIdentifier)->reset();
    }

    private function getEm(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function getConnection(): Connection
    {
        return $this->getEm()->getConnection();
    }
}
