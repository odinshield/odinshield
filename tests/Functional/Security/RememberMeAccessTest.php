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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEC-016 (audit M7, #186) — a remember-me cookie alone is enough for everyday browsing, while
 * sensitive pages keep IS_AUTHENTICATED_FULLY and send the user back to the login form.
 *
 * The cookie is obtained through a real form login with `_remember_me`; dropping the session
 * cookie afterwards is the "browser was closed overnight" case.
 */
final class RememberMeAccessTest extends AbstractWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function everydayPages(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'vulnerabilities' => ['/vulnerability'];
        yield 'software' => ['/software'];
        yield 'asset groups' => ['/asset-group'];
        yield 'manual chapter' => ['/manual/erste-schritte'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitivePages(): iterable
    {
        yield 'audit trail' => ['/audit'];
        yield 'user management' => ['/user'];
        yield 'user groups' => ['/user-group'];
        yield 'settings' => ['/settings'];
    }

    #[DataProvider('everydayPages')]
    public function testRememberMeCookieIsEnoughForEverydayPages(string $path): void
    {
        $this->loginWithRememberMeOnly();

        $this->client->request('GET', $path);

        $this->assertResponseIsSuccessful();
    }

    public function testRememberMeCookieReachesManualSearchIndex(): void
    {
        $this->loginWithRememberMeOnly();

        // The index is a build artefact, absent in a fresh checkout: a 404 proves the gate let the
        // request through, a redirect would mean it did not.
        $this->client->request('GET', '/pagefind/does-not-exist.js');

        $this->assertResponseStatusCodeSame(404);
    }

    #[DataProvider('sensitivePages')]
    public function testSensitivePagesRequireFreshLogin(string $path): void
    {
        $this->loginWithRememberMeOnly();

        $this->client->request('GET', $path);

        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        /** @var TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');
        $this->assertSelectorTextContains('.alert-info', $translator->trans(
            'security.login.reauth_required',
            ['%user%' => 'sec016user'],
            'messages',
            $this->client->getRequest()->getLocale(),
        ));
        $this->assertInputValueSame('_username', 'sec016user');
    }

    private function loginWithRememberMeOnly(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('sec016user');
        $user->setFirstName('SEC');
        $user->setLastName('Sixteen');
        $user->setEmail('sec016user@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setEmailNotifications(false);
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_ADMIN, User::ROLE_ISB, User::ROLE_SOFTWARE_OWNER, User::ROLE_HARDWARE_OWNER]);
        $em->persist($user);
        $em->flush();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            '_username' => 'sec016user',
            '_password' => 'TestPass123!',
            '_remember_me' => 'on',
        ]);
        $this->client->submit($form, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertResponseRedirects();

        $cookieJar = $this->client->getCookieJar();
        $this->assertNotNull($cookieJar->get('REMEMBERME'), 'Form login with _remember_me must set the cookie.');

        foreach ($cookieJar->all() as $cookie) {
            if ('REMEMBERME' !== $cookie->getName()) {
                $cookieJar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
