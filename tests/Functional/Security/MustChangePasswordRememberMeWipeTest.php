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

/**
 * SEC-004 — Remember-me tokens must be wiped when a user completes the
 * forced password rotation (`mustChangePassword=true`). The prior credential
 * is no longer trusted, so any series-based remember-me token issued under
 * it has to die at the same moment the password rotates.
 */
final class MustChangePasswordRememberMeWipeTest extends AbstractWebTestCase
{
    private const NEW_PASSWORD = 'BrandNew456!';

    public function testForcedPasswordChangeWipesUsersRememberMeTokens(): void
    {
        $user = $this->createForcedRotationUser('sec004user');
        $this->seedRememberMeToken('sec004user', 'series-A');
        $this->seedRememberMeToken('sec004user', 'series-B');

        $this->client->loginUser($user);
        $this->submitChangePassword();

        $this->assertResponseRedirects('/login');
        $this->assertSame(0, $this->countTokensFor('sec004user'));
    }

    public function testForcedPasswordChangeLeavesOtherUsersTokensIntact(): void
    {
        $victim = $this->createForcedRotationUser('sec004victim');
        $this->seedRememberMeToken('sec004victim', 'victim-series');
        $this->seedRememberMeToken('sec004otheruser', 'other-series');

        $this->client->loginUser($victim);
        $this->submitChangePassword();

        $this->assertResponseRedirects('/login');
        $this->assertSame(0, $this->countTokensFor('sec004victim'));
        $this->assertSame(1, $this->countTokensFor('sec004otheruser'), 'Other users\' remember-me tokens must not be affected.');
    }

    public function testChangePasswordRedirectsAwayWhenFlagIsNotSet(): void
    {
        // Guard the precondition: without mustChangePassword=true the controller
        // bounces to the dashboard before any wipe can happen. Protects against
        // a regression where a victim's tokens could be cleared just by hitting
        // the route.
        $user = $this->createForcedRotationUser('sec004clean');
        $user->setMustChangePassword(false);
        $this->getEm()->flush();
        $this->seedRememberMeToken('sec004clean', 'untouched-series');

        $this->client->loginUser($user);
        $this->client->request('GET', '/change-password');

        $this->assertResponseRedirects('/');
        $this->assertSame(1, $this->countTokensFor('sec004clean'));
    }

    private function submitChangePassword(): void
    {
        // See UserSettingsCurrentPasswordTest::submitSettings — same stateless
        // CSRF accommodation: Sec-Fetch-Site for the origin signal, `_token`
        // mirrored to the placeholder (= cookie name) so the validator
        // accepts the request via the origin branch.
        $this->client->request(
            'POST',
            '/change-password',
            [
                'change_password' => [
                    'plainPassword' => [
                        'first' => self::NEW_PASSWORD,
                        'second' => self::NEW_PASSWORD,
                    ],
                    '_token' => 'csrf-token',
                ],
            ],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function createForcedRotationUser(string $username): User
    {
        $em = $this->getEm();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('SEC');
        $user->setLastName('Four');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'OldPass123!'));
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setEmailNotifications(false);
        $user->setMustChangePassword(true);
        $user->setRoles([]);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedRememberMeToken(string $username, string $series): void
    {
        $this->getConnection()->executeStatement(
            'INSERT INTO rememberme_token (series, value, lastUsed, class, username) VALUES (:series, :value, :lastUsed, :class, :username)',
            [
                'series' => $series,
                'value' => bin2hex(random_bytes(16)),
                'lastUsed' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'class' => User::class,
                'username' => $username,
            ],
        );
    }

    private function countTokensFor(string $username): int
    {
        return (int) $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM rememberme_token WHERE username = :username',
            ['username' => $username],
        );
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
