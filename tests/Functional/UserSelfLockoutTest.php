<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * #187 — an admin who tries to deactivate or de-admin their own account is stopped, and the form
 * says why instead of silently reloading.
 */
final class UserSelfLockoutTest extends AbstractWebTestCase
{
    public function testSelfDeactivationIsRejectedWithMessage(): void
    {
        $admin = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$admin->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form();
        $this->choiceField($form, 'user[isActive]')->untick();
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-danger', $this->translated('user.self_lockout_blocked'));
        $this->assertTrue($this->reload($admin)->isActive());
    }

    public function testSelfDeAdminIsRejectedWithMessage(): void
    {
        $admin = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$admin->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form();
        $this->choiceField($form, 'user[roles]')->select([User::ROLE_ISB]);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-danger', $this->translated('user.self_lockout_blocked'));
        $this->assertContains(User::ROLE_ADMIN, $this->reload($admin)->getRoles());
    }

    /**
     * The rejected values were written into the managed entity, which is also the user of the
     * security token. Were they left there, the session would store the de-admined copy and
     * User::isEqualTo() (#184) would log the admin out on the next request.
     */
    public function testRejectedAttemptKeepsTheAdminSignedIn(): void
    {
        $admin = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$admin->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form();
        $this->choiceField($form, 'user[roles]')->select([User::ROLE_ISB]);
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/user');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Same leak without the lockout guard: an own-record edit that fails validation must not
     * leave its unsaved role change in the session either.
     */
    public function testInvalidOwnEditKeepsTheAdminSignedIn(): void
    {
        $admin = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/user/'.$admin->getUuid().'/edit');
        $form = $crawler->filter('form[name="user"]')->form();
        $this->choiceField($form, 'user[roles]')->select([User::ROLE_ADMIN]);
        $form['user[email]'] = '';
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/user');
        $this->assertResponseIsSuccessful();
    }

    private function choiceField(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        $this->assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function translated(string $key): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');

        return $translator->trans($key, [], 'messages', 'en');
    }

    private function reload(User $user): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
