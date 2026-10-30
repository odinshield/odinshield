<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Email;

/**
 * #209 — a German-speaking ISB assigns a vulnerability to an English-speaking user: the whole
 * mail (subject and body) is in the recipient's language, not the one of the request.
 */
final class AssignmentMailLocaleTest extends AbstractWebTestCase
{
    public function testMailFollowsTheRecipientNotTheRequest(): void
    {
        $this->assignAndCaptureMail(requesterLocale: 'de', assigneeLocale: 'en');

        /** @var Email $email */
        $email = $this->getMailerMessage();
        $this->assertEmailSubjectContains($email, 'Vulnerability assigned to you');
        $this->assertEmailTextBodyContains($email, 'A vulnerability has been assigned to you in OdinShield.');
        $this->assertEmailTextBodyNotContains($email, 'Schwachstelle');
    }

    /**
     * A recipient who never chose a language gets the application default (de), not the
     * language of whoever triggered the mail.
     */
    public function testRecipientWithoutLanguageGetsTheDefault(): void
    {
        $this->assignAndCaptureMail(requesterLocale: 'en', assigneeLocale: null);

        /** @var Email $email */
        $email = $this->getMailerMessage();
        $this->assertEmailTextBodyNotContains($email, 'A vulnerability has been assigned to you');
        $this->assertEmailHtmlBodyContains($email, 'lang="de"');
    }

    private function assignAndCaptureMail(string $requesterLocale, ?string $assigneeLocale): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $assetGroup = $this->createAssetGroup();

        $isb = $this->user('isb209', $requesterLocale, [User::ROLE_ISB]);
        $assignee = $this->user('assignee209', $assigneeLocale, []);
        $assignee->setEmailNotifications(true);
        $em->flush();

        $this->client->loginUser($isb);
        $crawler = $this->client->request('GET', '/vulnerability/new');
        $form = $crawler->filter('form[name="vulnerability"]')->form([
            'vulnerability[title]' => 'Locale check',
            'vulnerability[status]' => VulnerabilityStatus::Open->value,
            'vulnerability[severity]' => VulnerabilitySeverity::High->value,
            'vulnerability[assetGroups]' => [$assetGroup->getUuid()->toRfc4122()],
            'vulnerability[discoveredAt]' => 'de' === $requesterLocale ? '15.01.2026' : '01/15/2026',
            'vulnerability[responsibleUser]' => (string) $assignee->getId(),
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->assertEmailCount(1);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $username, ?string $locale, array $roles): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName(ucfirst($username));
        $user->setLastName('Test');
        $user->setEmail($username.'@example.com');
        $user->setPassword('unused');
        $user->setIsActive(true);
        $user->setLocale($locale);
        $user->setMustChangePassword(false);
        $user->setEmailNotifications(false);
        $user->setRoles($roles);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
