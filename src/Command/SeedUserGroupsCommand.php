<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\AssetType;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'odinshield:user-group:seed',
    description: 'Seeds the database with test user groups.',
)]
class SeedUserGroupsCommand
{
    private const array GROUP_NAMES = [
        'Finance', 'Engineering', 'Operations', 'Security', 'Management',
        'HR', 'Marketing', 'Sales', 'Support', 'Legal',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(shortcut: 'c', description: 'Number of groups to create.')]
        int $count = 5,
    ): int {
        $count = min(max(1, $count), \count(self::GROUP_NAMES));

        $io->title(sprintf('Seeding %d test user groups', $count));

        $users = $this->entityManager->getRepository(User::class)->findAll();
        $assetTypes = $this->entityManager->getRepository(AssetType::class)->findAll();

        if ([] === $users) {
            $io->warning('No users found. Run odinshield:user:seed first.');

            return Command::SUCCESS;
        }

        if ([] === $assetTypes) {
            $io->warning('No asset types found. Create some asset types first.');

            return Command::SUCCESS;
        }

        $names = array_slice(self::GROUP_NAMES, 0, $count);
        $created = 0;
        $skipped = 0;

        foreach ($names as $name) {
            $existing = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => $name]);
            if (null !== $existing) {
                ++$skipped;
                continue;
            }

            $userGroup = new UserGroup();
            $userGroup->setName($name);
            $userGroup->setDescription(sprintf('Test group for %s department.', $name));

            $userSample = $this->randomSample($users, random_int(1, min(8, \count($users))));
            foreach ($userSample as $user) {
                $userGroup->addUser($user);
            }

            $atSample = $this->randomSample($assetTypes, random_int(1, min(3, \count($assetTypes))));
            foreach ($atSample as $at) {
                $userGroup->addAssetType($at);
            }

            $this->entityManager->persist($userGroup);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Created %d user groups (skipped %d duplicates).', $created, $skipped));

        return Command::SUCCESS;
    }

    /**
     * @template T
     *
     * @param T[] $items
     *
     * @return T[]
     */
    private function randomSample(array $items, int $size): array
    {
        $keys = array_rand($items, min($size, \count($items)));
        $keys = \is_array($keys) ? $keys : [$keys];

        return array_map(static fn ($k) => $items[$k], $keys);
    }
}
