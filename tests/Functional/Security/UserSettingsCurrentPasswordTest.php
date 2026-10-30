<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-001 — Current-password re-check on /settings.
 *
 * The UserSettingsType FormEvents::PRE_SUBMIT listener attaches the
 * `currentPassword` field with a UserPassword constraint only when
 * `plainPassword` is non-empty or the e-mail address changes (#193).
 * Cosmetic edits (locale, theme, …) must
 * therefore go through without a current-password challenge.
 */
final class UserSettingsCurrentPasswordTest extends AbstractWebTestCase
{
    private const OLD_PASSWORD = 'OldPass123!';
    private const NEW_PASSWORD = 'NewPass456!';

    public function testCosmeticEditWithoutCurrentPasswordIsAccepted(): void
    {
        $user = $this->loginAsRegularUser();

        $this->submitSettings([
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => 'en',
            'theme' => 'dark',
            'emailNotifications' => '1',
            'currentPassword' => '',
            'plainPassword' => ['first' => '', 'second' => ''],
        ]);

        $this->assertResponseRedirects('/settings');

        $reloaded = $this->reloadUser($user->getUserIdentifier());
        $this->assertSame('en', $reloaded->getLocale());
    }

    public function testPasswordChangeWithoutCurrentPasswordIsRejected(): void
    {
        $user = $this->loginAsRegularUser();
        $originalHash = $user->getPassword();

        $this->submitSettings([
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => $user->getLocale() ?? 'de',
            'theme' => '',
            'emailNotifications' => '1',
            'currentPassword' => '',
            'plainPassword' => [
                'first' => self::NEW_PASSWORD,
                'second' => self::NEW_PASSWORD,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);

        $reloaded = $this->reloadUser($user->getUserIdentifier());
        $this->assertSame($originalHash, $reloaded->getPassword(), 'Password must remain unchanged when currentPassword is missing.');
    }

    public function testPasswordChangeWithWrongCurrentPasswordIsRejected(): void
    {
        $user = $this->loginAsRegularUser();
        $originalHash = $user->getPassword();

        $this->submitSettings([
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => $user->getLocale() ?? 'de',
            'theme' => '',
            'emailNotifications' => '1',
            'currentPassword' => 'WrongPass999!',
            'plainPassword' => [
                'first' => self::NEW_PASSWORD,
                'second' => self::NEW_PASSWORD,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);

        $reloaded = $this->reloadUser($user->getUserIdentifier());
        $this->assertSame($originalHash, $reloaded->getPassword(), 'Password must remain unchanged when currentPassword is wrong.');
    }

    public function testPasswordChangeWithCorrectCurrentPasswordSucceeds(): void
    {
        $user = $this->loginAsRegularUser();
        $originalHash = $user->getPassword();

        $this->submitSettings([
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => $user->getLocale() ?? 'de',
            'theme' => '',
            'emailNotifications' => '1',
            'currentPassword' => self::OLD_PASSWORD,
            'plainPassword' => [
                'first' => self::NEW_PASSWORD,
                'second' => self::NEW_PASSWORD,
            ],
        ]);

        $this->assertResponseRedirects('/settings');

        $reloaded = $this->reloadUser($user->getUserIdentifier());
        $this->assertNotSame($originalHash, $reloaded->getPassword(), 'Password hash must change after a successful update.');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');
        $this->assertTrue($hasher->isPasswordValid($reloaded, self::NEW_PASSWORD));
    }

    public function testEmailChangeWithoutCurrentPasswordIsRejected(): void
    {
        $user = $this->loginAsRegularUser();

        $this->submitSettings($this->emailChange($user, ''));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('sec001@example.com', $this->reloadUser($user->getUserIdentifier())->getEmail());
    }

    public function testEmailChangeWithWrongCurrentPasswordIsRejected(): void
    {
        $user = $this->loginAsRegularUser();

        $this->submitSettings($this->emailChange($user, 'WrongPass999!'));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('sec001@example.com', $this->reloadUser($user->getUserIdentifier())->getEmail());
    }

    public function testEmailChangeWithCorrectCurrentPasswordSucceeds(): void
    {
        $user = $this->loginAsRegularUser();

        $this->submitSettings($this->emailChange($user, self::OLD_PASSWORD));

        $this->assertResponseRedirects('/settings');
        $this->assertSame('sec001-new@example.com', $this->reloadUser($user->getUserIdentifier())->getEmail());
    }

    /**
     * @return array<string, mixed>
     */
    private function emailChange(User $user, string $currentPassword): array
    {
        return [
            'email' => 'sec001-new@example.com',
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => $user->getLocale() ?? 'de',
            'theme' => '',
            'emailNotifications' => '1',
            'currentPassword' => $currentPassword,
            'plainPassword' => ['first' => '', 'second' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitSettings(array $fields): void
    {
        // Stateless CSRF accepts a same-origin Sec-Fetch-Site header as one
        // valid signal (origin path) — but the form-level CSRF check still
        // rejects an empty `_token` value. Mirror the server-rendered
        // placeholder (the cookie name, `csrf-token`) so the validator falls
        // through to the origin-based acceptance branch.
        $fields['_token'] = 'csrf-token';
        $this->client->request(
            'POST',
            '/settings',
            ['user_settings' => $fields],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function loginAsRegularUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('sec001');
        $user->setFirstName('SEC');
        $user->setLastName('One');
        $user->setEmail('sec001@example.com');
        $user->setPassword($hasher->hashPassword($user, self::OLD_PASSWORD));
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setEmailNotifications(true);
        $user->setMustChangePassword(false);
        $user->setRoles([]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        // Reset the persisted password_change_limiter so repeated test runs within the hour don't
        // accumulate this fixed identifier into a 429.
        $this->resetActionLimiters($user->getUserIdentifier());

        return $user;
    }

    private function reloadUser(string $username): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy(['username' => $username]);
        $this->assertNotNull($user);

        return $user;
    }
}
