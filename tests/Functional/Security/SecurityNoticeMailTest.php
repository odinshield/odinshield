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
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * #269 — an e-mail change (own or by an admin) is reported to the OLD address, a voluntary
 * password change to the address before the save; both regardless of emailNotifications.
 */
final class SecurityNoticeMailTest extends AbstractWebTestCase
{
    private const PASSWORD = 'OldPass123!';
    private const OLD_EMAIL = 'sec269@example.com';
    private const NEW_EMAIL = 'sec269-new@example.com';

    public function testOwnEmailChangeMailsTheOldAddressOnly(): void
    {
        $user = $this->loginAs($this->createUser(emailNotifications: false));

        $this->submitSettings($user, email: self::NEW_EMAIL, currentPassword: self::PASSWORD);

        $this->assertResponseRedirects('/settings');
        $this->assertEmailCount(1);
        $email = $this->mail(0);
        $this->assertRecipient(self::OLD_EMAIL, $email);
        $this->assertEmailSubjectContains($email, 'Ihre E-Mail-Adresse wurde geändert');
        $this->assertEmailTextBodyContains($email, 'se***@example.com');
        $this->assertEmailTextBodyNotContains($email, self::NEW_EMAIL);
        $this->assertEmailTextBodyNotContains($email, 'Ein Administrator');
    }

    public function testSaveWithoutEmailOrPasswordChangeSendsNothing(): void
    {
        $user = $this->loginAs($this->createUser());

        $this->submitSettings($user, email: self::OLD_EMAIL, currentPassword: '');

        $this->assertResponseRedirects('/settings');
        $this->assertEmailCount(0);
    }

    public function testVoluntaryPasswordChangeMailsTheCurrentAddress(): void
    {
        $user = $this->loginAs($this->createUser(emailNotifications: false));

        $this->submitSettings($user, email: self::OLD_EMAIL, currentPassword: self::PASSWORD, newPassword: 'NewPass456!');

        $this->assertResponseRedirects('/settings');
        $this->assertEmailCount(1);
        $email = $this->mail(0);
        $this->assertRecipient(self::OLD_EMAIL, $email);
        $this->assertEmailSubjectContains($email, 'Ihr Passwort wurde geändert');
    }

    public function testEmailAndPasswordChangedTogetherBothGoToTheOldAddress(): void
    {
        $user = $this->loginAs($this->createUser());

        $this->submitSettings($user, email: self::NEW_EMAIL, currentPassword: self::PASSWORD, newPassword: 'NewPass456!');

        $this->assertResponseRedirects('/settings');
        $this->assertEmailCount(2);
        $this->assertRecipient(self::OLD_EMAIL, $this->mail(0));
        $this->assertRecipient(self::OLD_EMAIL, $this->mail(1));
    }

    public function testAdminEmailChangeMailsTheOldAddressInTheUsersLanguage(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form(['user[email]' => self::NEW_EMAIL]);
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->assertEmailCount(1);
        $email = $this->mail(0);
        $this->assertRecipient(self::OLD_EMAIL, $email);
        $this->assertEmailTextBodyContains($email, 'Ein Administrator hat die E-Mail-Adresse');
        $this->assertEmailTextBodyContains($email, 'se***@example.com');
    }

    public function testAdminPasswordResetSendsNothing(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form([
            'user[plainPassword][first]' => 'ResetPass789!',
            'user[plainPassword][second]' => 'ResetPass789!',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->assertEmailCount(0);
    }

    private function mail(int $index): Email
    {
        $email = $this->getMailerMessage($index);
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function assertRecipient(string $address, Email $email): void
    {
        $this->assertSame([$address], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
    }

    private function submitSettings(User $user, string $email, string $currentPassword, string $newPassword = ''): void
    {
        // Same CSRF approach as UserSettingsCurrentPasswordTest: the placeholder token plus a
        // same-origin Sec-Fetch-Site header pass the stateless CSRF check.
        $this->client->request('POST', '/settings', ['user_settings' => [
            'email' => $email,
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => 'de',
            'theme' => '',
            'emailNotifications' => $user->isEmailNotifications() ? '1' : '',
            'currentPassword' => $currentPassword,
            'plainPassword' => ['first' => $newPassword, 'second' => $newPassword],
            '_token' => 'csrf-token',
        ]], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function loginAs(User $user): User
    {
        $this->client->loginUser($user);
        $this->resetActionLimiters($user->getUserIdentifier());

        return $user;
    }

    private function createUser(bool $emailNotifications = true): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('sec269');
        $user->setFirstName('Sec');
        $user->setLastName('Notice');
        $user->setEmail(self::OLD_EMAIL);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setEmailNotifications($emailNotifications);
        $user->setMustChangePassword(false);
        $user->setRoles([]);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
