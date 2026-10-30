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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * #188 — the forced password change is a new user's very first screen.
 */
final class ForcedPasswordChangeUxTest extends AbstractWebTestCase
{
    public function testHelpDescribesThePolicyInsteadOfTheAdminHint(): void
    {
        $this->client->loginUser($this->forcedUser());

        $crawler = $this->client->request('GET', '/change-password');

        self::assertResponseIsSuccessful();
        $page = $crawler->filter('form:not([action="/logout"])')->text();
        self::assertStringNotContainsString('Leave blank', $page);
        self::assertStringContainsString($this->policyHint(), $page);
    }

    /**
     * #239: the policy is named wherever a password is set — also when an admin creates a user
     * and in one's own settings, not only on the forced first change.
     */
    public function testPolicyHintOnEveryPasswordForm(): void
    {
        $this->loginAsAdmin();

        foreach (['/user/new', '/settings'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($this->policyHint(), $crawler->filter('form:not([action="/logout"])')->text(), $path);
        }
    }

    private function policyHint(): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');
        self::assertInstanceOf(\Symfony\Contracts\Translation\LocaleAwareInterface::class, $translator);
        $translator->setLocale('en');
        /** @var \App\Security\PasswordPolicy $policy */
        $policy = $this->client->getContainer()->get(\App\Security\PasswordPolicy::class);

        return $policy->hint($translator);
    }

    public function testLoginConfirmsTheChangedPassword(): void
    {
        $this->client->loginUser($this->forcedUser());
        $crawler = $this->client->request('GET', '/change-password');

        $form = $crawler->filter('form:not([action="/logout"])')->form([
            'change_password[plainPassword][first]' => 'Ärger2026!ok',
            'change_password[plainPassword][second]' => 'Ärger2026!ok',
        ]);
        $this->client->submit($form, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        // The login page is anonymous and renders in the default language, not the user's.
        self::assertSelectorTextContains('.alert-success', $this->trans('security.change_password.success', $this->client->getRequest()->getLocale()));
        // Logged out: the confirmation sits on the login form, not inside a session.
        self::assertSelectorExists('input[name="_password"]');
    }

    public function testBlockedNavigationDoesNotPileUpUnseenWarnings(): void
    {
        $this->client->loginUser($this->forcedUser());

        $this->client->request('GET', '/vulnerability');
        self::assertResponseRedirects('/change-password');
        $this->client->request('GET', '/software');
        $this->client->request('GET', '/change-password');

        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Session\Session::class, $session);
        self::assertSame([], $session->getFlashBag()->peek('warning'));
    }

    private function forcedUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('newbie188');
        $user->setFirstName('New');
        $user->setLastName('Bie');
        $user->setEmail('newbie188@example.com');
        $user->setPassword($hasher->hashPassword($user, 'Initial123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setEmailNotifications(false);
        $user->setMustChangePassword(true);
        $user->setRoles([]);
        $em->persist($user);
        $em->flush();

        $this->resetActionLimiters('newbie188');

        return $user;
    }

    private function trans(string $key, string $locale = 'en'): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');

        return $translator->trans($key, [], 'messages', $locale);
    }
}
