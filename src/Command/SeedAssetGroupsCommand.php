<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'odinshield:asset-group:seed',
    description: 'Seeds the database with test asset types and asset groups.',
)]
class SeedAssetGroupsCommand
{
    /** @var array<string, array{description: string, groups: list<array{name: string, description: string}>}> */
    private const array DATA = [
        'Web Application' => [
            'description' => 'Customer-facing and internal web applications.',
            'groups' => [
                ['name' => 'Customer Portal', 'description' => 'Public-facing self-service portal for customers.'],
                ['name' => 'Admin Dashboard', 'description' => 'Internal administration interface.'],
                ['name' => 'Partner Extranet', 'description' => 'Extranet for B2B partner access.'],
                ['name' => 'Marketing Website', 'description' => 'Corporate website and landing pages.'],
            ],
        ],
        'API / Microservice' => [
            'description' => 'REST and GraphQL APIs, internal microservices.',
            'groups' => [
                ['name' => 'Authentication Service', 'description' => 'OAuth2/OIDC identity provider.'],
                ['name' => 'Payment Gateway Integration', 'description' => 'Payment processing microservice.'],
                ['name' => 'Notification API', 'description' => 'Push/email/SMS notification service.'],
                ['name' => 'Reporting API', 'description' => 'Business intelligence and export endpoints.'],
            ],
        ],
        'Infrastructure' => [
            'description' => 'Network equipment, servers, and cloud resources.',
            'groups' => [
                ['name' => 'Production Kubernetes Cluster', 'description' => 'Main container orchestration platform.'],
                ['name' => 'VPN Gateway', 'description' => 'Corporate VPN concentrator.'],
                ['name' => 'Firewall & Load Balancer', 'description' => 'Edge security appliances.'],
                ['name' => 'CI/CD Pipeline', 'description' => 'Build and deployment infrastructure.'],
            ],
        ],
        'Database' => [
            'description' => 'Relational and NoSQL data stores.',
            'groups' => [
                ['name' => 'Customer Database', 'description' => 'Primary PostgreSQL cluster holding PII.'],
                ['name' => 'Analytics Data Warehouse', 'description' => 'Columnar store for reporting workloads.'],
                ['name' => 'Cache Layer', 'description' => 'Redis cluster for session and object caching.'],
            ],
        ],
        'Mobile Application' => [
            'description' => 'iOS and Android native and hybrid apps.',
            'groups' => [
                ['name' => 'iOS Customer App', 'description' => 'Native Swift app on the App Store.'],
                ['name' => 'Android Customer App', 'description' => 'Native Kotlin app on Google Play.'],
            ],
        ],
        'Endpoint / Desktop' => [
            'description' => 'Employee workstations and managed devices.',
            'groups' => [
                ['name' => 'Developer Workstations', 'description' => 'Laptops used by engineering staff.'],
                ['name' => 'Office Network Endpoints', 'description' => 'Printers, meeting-room devices, IoT.'],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Seeding asset types and asset groups');

        $typesCreated = 0;
        $groupsCreated = 0;
        $skipped = 0;

        foreach (self::DATA as $typeName => $typeData) {
            $assetType = $this->entityManager->getRepository(AssetType::class)->findOneBy(['name' => $typeName]);
            if (null === $assetType) {
                $assetType = new AssetType();
                $assetType->setName($typeName);
                $assetType->setDescription($typeData['description']);
                $assetType->setActive(true);
                $this->entityManager->persist($assetType);
                ++$typesCreated;
            }

            foreach ($typeData['groups'] as $groupData) {
                $existing = $this->entityManager->getRepository(AssetGroup::class)->findOneBy(['name' => $groupData['name']]);
                if (null !== $existing) {
                    ++$skipped;
                    continue;
                }

                $group = new AssetGroup();
                $group->setName($groupData['name']);
                $group->setDescription($groupData['description']);
                $group->setAssetType($assetType);
                $group->setActive(true);
                $this->entityManager->persist($group);
                ++$groupsCreated;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Created %d asset type(s) and %d asset group(s) (skipped %d existing).',
            $typesCreated,
            $groupsCreated,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
