<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\AssetGroup;
use App\Entity\Vulnerability;
use App\Enum\RemediationPriority;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use App\Repository\AssetGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'odinshield:vulnerability:seed',
    description: 'Seeds the database with test vulnerabilities.',
)]
class SeedVulnerabilitiesCommand
{
    private const array TITLES = [
        'SQL Injection in login form',
        'Outdated OpenSSL library',
        'Privilege escalation via sudo misconfiguration',
        'Cross-Site Scripting in search field',
        'Insecure direct object reference in user API',
        'Hardcoded credentials in configuration file',
        'Missing CSRF protection on admin actions',
        'Path traversal in file upload endpoint',
        'XML External Entity (XXE) injection',
        'Server-Side Request Forgery (SSRF)',
        'Insecure deserialization in session handler',
        'Exposed debug endpoint in production',
        'Weak TLS cipher suites enabled',
        'Missing rate limiting on authentication endpoint',
        'Outdated Apache HTTP Server',
        'Command injection in log processing script',
        'Broken access control on admin panel',
        'Sensitive data in HTTP response headers',
        'Unpatched kernel vulnerability (CVE-based)',
        'Cleartext storage of passwords in database',
        'Open redirect in OAuth callback',
        'JWT algorithm confusion attack',
        'Mass assignment vulnerability in REST API',
        'Prototype pollution in JavaScript library',
        'Denial of Service via regex backtracking',
        'Race condition in file permission check',
        'Improper certificate validation',
        'Directory listing enabled on web server',
        'Reflected file download vulnerability',
        'Insufficient logging of security events',
    ];

    private const array WEIGHTED_STATUSES = [
        VulnerabilityStatus::Open,
        VulnerabilityStatus::Open,
        VulnerabilityStatus::Open,
        VulnerabilityStatus::InProgress,
        VulnerabilityStatus::InProgress,
        VulnerabilityStatus::InProgress,
        VulnerabilityStatus::Resolved,
        VulnerabilityStatus::Resolved,
        VulnerabilityStatus::Accepted,
        VulnerabilityStatus::Deferred,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetGroupRepository $assetGroupRepository,
        private readonly SeedAssetGroupsCommand $assetGroupSeeder,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(shortcut: 'c', description: 'Number of vulnerabilities to create.')]
        int $count = 50,
    ): int {
        $count = max(1, $count);

        $assetGroups = $this->assetGroupRepository->findAll();
        if ([] === $assetGroups) {
            $io->comment('No asset groups found — running odinshield:asset-group:seed first.');
            ($this->assetGroupSeeder)($io);
            $assetGroups = $this->assetGroupRepository->findAll();
        }

        if ([] === $assetGroups) {
            $io->error('Could not create asset groups. Aborting.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Seeding %d test vulnerabilities', $count));

        $created = 0;
        $skipped = 0;

        $sources = VulnerabilitySource::cases();
        $priorities = RemediationPriority::cases();

        for ($i = 1; $i <= $count; ++$i) {
            $title = self::TITLES[array_rand(self::TITLES)];

            if (null !== $this->entityManager->getRepository(Vulnerability::class)->findOneBy(['title' => $title])) {
                ++$skipped;
                continue;
            }

            $cvssScore = round(mt_rand(1, 100) / 10, 1);
            $status = self::WEIGHTED_STATUSES[array_rand(self::WEIGHTED_STATUSES)];
            $source = $sources[array_rand($sources)];

            $vulnerability = new Vulnerability();
            $vulnerability->setTitle($title);
            $vulnerability->setCveId($this->seedCveId($source, $i));
            $vulnerability->setCvssScore((string) $cvssScore);
            $vulnerability->setSeverity(VulnerabilitySeverity::fromCvssScore($cvssScore));
            $vulnerability->setStatus($status);
            $vulnerability->setSource($source);
            $this->attachSourceEvidence($vulnerability, $source, $i);
            $vulnerability->setRemediationPriority($priorities[array_rand($priorities)]);
            $vulnerability->setDiscoveredAt(new \DateTimeImmutable(sprintf('-%d days', mt_rand(1, 365))));

            $groupCount = random_int(1, min(3, \count($assetGroups)));
            $groupKeys = (array) array_rand($assetGroups, $groupCount);
            $pickedGroups = [];
            foreach ($groupKeys as $k) {
                /** @var AssetGroup $ag */
                $ag = $assetGroups[$k];
                $vulnerability->addAssetGroup($ag);
                $pickedGroups[] = $ag;
            }

            // Roll a per-occurrence status so seeded data shows mixed resolution progress.
            foreach ($vulnerability->getOccurrences() as $occurrence) {
                $roll = mt_rand(1, 100);
                if ($roll <= 60) {
                    $occurrence->setStatus(VulnerabilityStatus::Open);
                } elseif ($roll <= 80) {
                    $occurrence->setStatus(VulnerabilityStatus::InProgress);
                } else {
                    $occurrence->setStatus(VulnerabilityStatus::Resolved);
                    $occurrence->setResolvedAt(new \DateTimeImmutable(sprintf('-%d days', mt_rand(0, 14))));
                }
            }

            $softwarePool = [];
            foreach ($pickedGroups as $ag) {
                foreach ($ag->getSoftware() as $sw) {
                    $softwarePool[spl_object_id($sw)] = $sw;
                }
            }
            if ([] !== $softwarePool && 1 === mt_rand(0, 1)) {
                $softwareList = array_values($softwarePool);
                $swCount = random_int(1, min(2, \count($softwareList)));
                $swKeys = (array) array_rand($softwareList, $swCount);
                foreach ($swKeys as $k) {
                    $vulnerability->addSoftware($softwareList[$k]);
                }
            }

            if (mt_rand(0, 1)) {
                $vulnerability->setDueDate(new \DateTimeImmutable(sprintf('+%d days', mt_rand(7, 90))));
            }

            if ($status->isTerminal()) {
                $vulnerability->setResolvedAt(new \DateTimeImmutable(sprintf('-%d days', mt_rand(0, 30))));
            }

            $this->entityManager->persist($vulnerability);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Created %d vulnerabilities (skipped %d duplicates).', $created, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Issue #59: feed-sourced seed rows get CVE ids from a year that will never exist, so a seeded
     * row can never collide with a real CVE — and the exploitation enricher (#134) never asks
     * FIRST/KEV about a CVE that does not exist.
     */
    private function seedCveId(VulnerabilitySource $source, int $n): string
    {
        if (VulnerabilitySource::Manual === $source) {
            return sprintf('CVE-%d-%05d', mt_rand(2020, 2026), mt_rand(1000, 99999));
        }

        return sprintf('CVE-2999-%05d', $n);
    }

    /**
     * Issue #59: a seeded row labelled `csaf` without the matching evidence column contradicts
     * the rule the importer relies on — and it made past diagnosis harder, because label and
     * provenance disagreed in exactly the way a real defect would look.
     */
    private function attachSourceEvidence(Vulnerability $vulnerability, VulnerabilitySource $source, int $n): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        match ($source) {
            VulnerabilitySource::Csaf => $vulnerability->setCsafSources([[
                'provider' => 'seed',
                'docId' => sprintf('SEED-CSAF-%05d', $n),
                'version' => '1',
                'syncedAt' => $now,
            ]]),
            VulnerabilitySource::Manual => null,
        };
    }
}
