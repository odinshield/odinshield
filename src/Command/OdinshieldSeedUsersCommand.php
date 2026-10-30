<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'odinshield:user:seed',
    description: 'Seeds the database with test users.',
)]
class OdinshieldSeedUsersCommand
{
    private const array FIRST_NAMES = [
        'Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hans',
        'Ida', 'Jonas', 'Klara', 'Leon', 'Maria', 'Nico', 'Olga', 'Paul',
        'Quinn', 'Rosa', 'Stefan', 'Tina', 'Uwe', 'Vera', 'Walter', 'Xenia',
        'Yara', 'Zoe',
    ];

    private const array LAST_NAMES = [
        'Bauer', 'Fischer', 'Hoffmann', 'Koch', 'Krause', 'Lehmann', 'Meyer',
        'Müller', 'Neumann', 'Peters', 'Richter', 'Schmid', 'Schmidt',
        'Schneider', 'Schulz', 'Wagner', 'Weber', 'Werner', 'Wolf', 'Zimmermann',
    ];

    private const array ROLES = [
        User::ROLE_USER,
        User::ROLE_ADMIN,
        User::ROLE_SOFTWARE_OWNER,
        User::ROLE_HARDWARE_OWNER,
        User::ROLE_ISB,
    ];

    /**
     * I-06 (#200): the command ships in the prod image, and with its defaults it creates users with
     * a known password, random roles up to ADMIN/ISB and no forced password change.
     */
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(shortcut: 'c', description: 'Number of users to create.')]
        int $count = 25,
        #[Option(shortcut: 'p', description: 'Password for all seeded users.')]
        string $password = 'Test1234!',
        #[Option(description: 'Require all seeded users to change their password on next login.')]
        bool $mustChangePassword = false,
    ): int {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(\sprintf('odinshield:user:seed only runs in %s (current: %s) — it creates users with a known password and random admin roles.', implode('/', self::ALLOWED_ENVIRONMENTS), $this->environment));

            return Command::FAILURE;
        }

        $count = max(1, $count);
        $plainPassword = $password;

        $io->title(sprintf('Seeding %d test users', $count));

        $created = 0;
        $skipped = 0;
        $existingUsernames = [];

        for ($i = 1; $i <= $count; ++$i) {
            $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $username = $this->generateUsername($firstName, $lastName, $existingUsernames);
            $email = strtolower($username).'@example.com';
            $existingUsernames[] = $username;

            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (null !== $existing) {
                ++$skipped;
                continue;
            }

            $role = self::ROLES[array_rand(self::ROLES)];

            $user = new User();
            $user->setUsername($username);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->setIsActive(true);
            $user->setLocale('de');
            $user->setRoles(User::ROLE_USER === $role ? [] : [$role]);
            $user->setMustChangePassword($mustChangePassword);

            $this->entityManager->persist($user);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Created %d users (skipped %d duplicates). Password: %s', $created, $skipped, $plainPassword));

        return Command::SUCCESS;
    }

    /**
     * @param string[] $existing
     */
    private function generateUsername(string $firstName, string $lastName, array $existing): string
    {
        $base = strtolower(substr($firstName, 0, 1).$lastName);
        $base = preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $base) ?: $base) ?? '';
        $username = $base;
        $suffix = 2;

        while (in_array($username, $existing, true)) {
            $username = $base.$suffix;
            ++$suffix;
        }

        return $username;
    }
}
