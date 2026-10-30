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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEC-015 (#184) — an existing session must not outlive a change to the account behind it.
 * Symfony only calls the UserChecker on authentication; a running session is kept alive by the
 * ContextListener, which asks User::isEqualTo() whether the reloaded user still matches.
 *
 * The account is changed via DBAL, not the EntityManager: the test client clears the EM at every
 * request boundary anyway, and a raw UPDATE is what a second admin's request amounts to.
 */
final class DeactivatedUserSessionTest extends AbstractWebTestCase
{
    public function testDeactivatedUserIsLoggedOutOnNextRequest(): void
    {
        $this->client->loginUser($this->createUser('sec015user'));
        $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();

        $this->updateUser('sec015user', 'is_active', false);

        $this->client->request('GET', '/vulnerability');
        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        /** @var TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');
        $this->assertSelectorTextContains(
            '.alert-danger',
            $translator->trans('security.account_disabled', [], 'security', $this->client->getRequest()->getLocale()),
        );
    }

    public function testRoleChangeEndsSession(): void
    {
        $this->client->loginUser($this->createUser('sec015roles'));
        $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();

        $this->updateUser('sec015roles', 'roles', json_encode([User::ROLE_ISB], \JSON_THROW_ON_ERROR));

        $this->client->request('GET', '/vulnerability');
        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        $this->assertSelectorNotExists('.alert-danger');
    }

    public function testPasswordChangeEndsSession(): void
    {
        $this->client->loginUser($this->createUser('sec015pass'));
        $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();

        $this->updateUser('sec015pass', 'password', password_hash('Other123!', \PASSWORD_BCRYPT));

        $this->client->request('GET', '/vulnerability');
        $this->assertResponseRedirects('/login');
    }

    public function testUnrelatedProfileChangeKeepsSession(): void
    {
        $this->client->loginUser($this->createUser('sec015name'));
        $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();

        $this->updateUser('sec015name', 'last_name', 'Renamed');

        $this->client->request('GET', '/vulnerability');
        $this->assertResponseIsSuccessful();
    }

    private function createUser(string $username): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('SEC');
        $user->setLastName('Fifteen');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setEmailNotifications(false);
        $user->setMustChangePassword(false);
        $user->setRoles([]);

        $em = $this->getEm();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function updateUser(string $username, string $column, string|bool $value): void
    {
        $this->getConnection()->update('"user"', [$column => $value], ['username' => $username], [$column => \is_bool($value) ? 'boolean' : 'string']);
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
