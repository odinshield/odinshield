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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * SEC-018 / #140 — usernames are stored lowercase and resolved case-insensitively.
 *
 * Before this, 'CaseTest' and 'casetest' were two separate accounts (the unique index compares
 * byte-wise), and an account created with capitals could not be reached by typing it in lower
 * case: the user only saw "invalid credentials" and was locked out by throttling after five tries.
 */
final class UsernameCaseTest extends AbstractWebTestCase
{
    public function testLoginSucceedsWithADifferentSpelling(): void
    {
        $this->createUser('caseuser', 'Start0000!');

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            '_username' => 'CaseUser',
            '_password' => 'Start0000!',
        ]);
        $this->client->submit($form);

        // A successful form login redirects away from /login; a failed one renders it again.
        self::assertResponseRedirects();
        self::assertNotSame('/login', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));

        $this->client->followRedirect();
        $token = $this->client->getContainer()->get('security.token_storage')->getToken();
        self::assertNotNull($token, 'The user should be authenticated after logging in with a different spelling.');
        self::assertSame('caseuser', $token->getUserIdentifier());
    }

    public function testSecondAccountDifferingOnlyInSpellingIsRejected(): void
    {
        $this->createUser('caseuser', 'Start0000!');

        $duplicate = new User();
        $duplicate->setUsername('CASEUSER');
        $duplicate->setFirstName('Case');
        $duplicate->setLastName('Clash');
        $duplicate->setEmail('clash@example.com');
        $duplicate->setPassword('irrelevant');

        /** @var ValidatorInterface $validator */
        $validator = $this->client->getContainer()->get('validator');
        $violations = $validator->validate($duplicate);

        $fields = array_map(static fn ($v): string => $v->getPropertyPath(), iterator_to_array($violations));
        self::assertContains('username', $fields, 'The duplicate must surface as a form error, not as a database error.');
    }

    public function testAddUserCommandStoresLowercaseAndRefusesADifferentSpelling(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('odinshield:user:add'));

        $tester->execute([
            'username' => 'MMustermann',
            'first-name' => 'Max',
            'last-name' => 'Mustermann',
            'email' => 'max@example.com',
            'password' => 'Start0000!',
        ]);
        $tester->assertCommandIsSuccessful();

        $em = $this->getEm();
        $em->clear();
        self::assertNotNull(
            $em->getRepository(User::class)->findOneBy(['username' => 'mmustermann']),
            'The command must store the username lowercased.',
        );

        // The command aborts with an exception rather than a status code; either way the second
        // account must not come into existence.
        $second = new CommandTester($application->find('odinshield:user:add'));

        try {
            $second->execute([
                'username' => 'MMUSTERMANN',
                'first-name' => 'Moritz',
                'last-name' => 'Mustermann',
                'email' => 'moritz@example.com',
                'password' => 'Start0000!',
            ]);
            self::fail('A second account differing only in spelling must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('already a user registered', $e->getMessage());
            self::assertStringContainsString('mmustermann', $e->getMessage(), 'The message should name the stored spelling.');
        }

        $em->clear();
        self::assertCount(0, $em->getRepository(User::class)->findBy(['email' => 'moritz@example.com']));
    }

    private function createUser(string $username, string $password): User
    {
        $em = $this->getEm();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Case');
        $user->setLastName('User');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setMustChangePassword(false);
        $user->setRoles([]);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function getEm(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
