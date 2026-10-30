<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use App\Enum\RemediationPriority;
use App\Enum\MaintenanceSource;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'odinshield:import:legacy',
    description: 'Imports vulnerabilities from a legacy MariaDB dump file into OdinShield.',
)]
class ImportLegacyCommand extends Command
{
    // ── vulnerability_tbl positional column indices ────────────────────────
    private const int VULN_ID = 0;
    private const int VULN_IMPACT_NET = 1;
    private const int VULN_IMPACT_PROC = 2;
    private const int VULN_STATUS = 3;
    private const int VULN_OWNER = 4;
    private const int VULN_EXPOSURE = 5;
    private const int VULN_BRAND = 7;
    private const int VULN_SOFTWARE = 8;
    private const int VULN_VERSION = 9;
    private const int VULN_CVE = 10;
    private const int VULN_DESCRIPTION = 11;
    private const int VULN_PATCH_AVAILABLE = 14;
    private const int VULN_MITIGATIONS = 16;
    private const int VULN_LAST_REVIEW = 17;
    private const int VULN_COMMENTS = 18;
    private const int VULN_CREATED_BY = 19;
    private const int VULN_CREATED_AT = 20;
    private const int VULN_CHANGED_BY = 21;
    private const int VULN_CHANGED_AT = 22;
    private const int VULN_SOURCE = 23;
    private const int VULN_INTERNAL_EXPOSURE = 24;

    // ── vendor / software normalisation ───────────────────────────────────

    /** Maps lowercased legacy brand strings to a canonical vendor name. */
    private const array VENDOR_ALIASES = [
        'apache' => 'Apache Software Foundation',
        'cisco-talos' => 'Cisco',
        'clark cooper, et al. (expat)' => 'libexpat',
        'curl daniel stenberg' => 'curl',
        'curl.haxx.se' => 'curl',
        'das debian-projekt' => 'Debian',
        'das openbsd-projekt' => 'OpenBSD',
        'debian.org' => 'Debian',
        'debian remote maintainers gpl' => 'Debian',
        'git junio hamano' => 'Git',
        'gnome.org' => 'GNOME',
        'gnome projekt' => 'GNOME',
        'gnu projekt' => 'GNU Project',
        'junio hamano git' => 'Git',
        'ms' => 'Microsoft',
        'ntp' => 'NTP Project',
        'open source dpkg' => 'Debian',
        'open source expat' => 'libexpat',
        'open source libssh' => 'libssh',
        'open source pam' => 'Linux-PAM',
        'open-source-softwareprojekt' => 'Open Source',
        'openssh-projekt' => 'OpenSSH',
        'openssh / python3/paramiko' => 'OpenSSH',
        'postgresql' => 'PostgreSQL',
        'postgresql community' => 'PostgreSQL',
        'postgresql global development group' => 'PostgreSQL',
        'python software foundation, guido van rossum' => 'Python Software Foundation',
        'redhat' => 'Red Hat',
        'vim gpl' => 'Vim',
        'wayne davison rsync' => 'rsync',
        'wget' => 'GNU wget',
        'wireshark-community' => 'Wireshark',
    ];

    /** Maps lowercased legacy software strings to a canonical product name. */
    private const array SOFTWARE_CANONICALS = [
        'apache2' => 'Apache HTTP Server',
        'apache web server' => 'Apache HTTP Server',
        'apache tomcat' => 'Tomcat',
        'axiros (mariadb)' => 'MariaDB',
        'axiros (python)' => 'Python',
        'brocade fabric os' => 'Fabric OS',
        'checkmk enterprise (cee)' => 'Checkmk Enterprise',
        'checkmk' => 'Checkmk',
        'curl' => 'curl',
        'curl - redhat' => 'curl',
        'fabric os software' => 'Fabric OS',
        'fortimail' => 'FortiMail',
        'git' => 'Git',
        'junos os' => 'Junos',
        'junos' => 'Junos',
        'junosos' => 'Junos',
        'lan time' => 'LANTIME',
        'lantime' => 'LANTIME',
        'lantime-firmware' => 'LANTIME Firmware',
        'openssh-server' => 'OpenSSH',
        'owncloud' => 'ownCloud',
        'postgresql' => 'PostgreSQL',
        'python pypy' => 'PyPy',
        'python redhat' => 'Python',
        'vmware esxi' => 'ESXi',
        'vmware vcenter server' => 'vCenter Server',
        'vmware vcenter' => 'vCenter Server',
        'vmware vsphere esxi' => 'vSphere ESXi',
    ];

    // ── asset_type_tbl ─────────────────────────────────────────────────────
    private const int AT_ID = 0;
    private const int AT_NAME = 1;
    private const int AT_CATEGORY = 2;

    // ── group_tbl ──────────────────────────────────────────────────────────
    private const int GRP_ID = 0;
    private const int GRP_NAME = 2;

    // ── user ───────────────────────────────────────────────────────────────
    private const int USR_ID = 0;
    private const int USR_USERNAME = 2;

    /** @var array<string, Vendor> */
    private array $vendorCache = [];

    /** @var array<string, Software> */
    private array $softwareCache = [];

    /** @var array<string, AssetType> */
    private array $assetTypeCache = [];

    /** @var array<string, AssetGroup> */
    private array $assetGroupCache = [];

    /** @var array<string, User> */
    private array $userCache = [];

    private int $vendorsCreated = 0;
    private int $softwareCreated = 0;
    private int $assetTypesCreated = 0;
    private int $assetGroupsCreated = 0;
    private int $vulnsCreated = 0;
    private int $vulnsSkipped = 0;
    private int $occurrencesCreated = 0;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the legacy .bak dump file', 'gigabit-sqlbkp_20260429.bak')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Comma-separated legacy status IDs to import (1=neu,2=in Planung,3=in Umsetzung,4=Abgeschlossen,5=verworfen,6=umgesetzt)', '1,2,3,4,5,6')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and map without persisting any data')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N rows (0 = no limit)', '0')
            ->addOption('preserve-timestamps', null, InputOption::VALUE_NONE, 'Write legacy created_at/changed_at back via SQL UPDATE after flush')
            ->addOption('default-criticality', null, InputOption::VALUE_REQUIRED, 'Fallback AssetCriticality for new groups (critical/high/medium/low)', 'medium')
            ->addOption('default-exposure', null, InputOption::VALUE_REQUIRED, 'Fallback AssetExposure for new groups (internet/intranet/isolated)', 'intranet')
            ->addOption('default-data-sensitivity', null, InputOption::VALUE_REQUIRED, 'Fallback DataSensitivity for new groups (restricted/confidential/internal/public)', 'internal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OdinShield — Legacy MySQL import');

        // ── Options ──────────────────────────────────────────────────────────
        $filePath = (string) $input->getOption('file');
        if (!file_exists($filePath)) {
            $filePath = \dirname(__DIR__, 2).'/'.$filePath;
        }
        if (!is_readable($filePath)) {
            $io->error(sprintf('Dump file not found or not readable: %s', $filePath));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $preserveTimestamps = (bool) $input->getOption('preserve-timestamps');
        $importStatuses = array_map('intval', explode(',', (string) $input->getOption('status')));

        $criticality = AssetCriticality::from(strtolower((string) $input->getOption('default-criticality')));
        $exposure = AssetExposure::from(strtolower((string) $input->getOption('default-exposure')));
        $dataSensitivity = DataSensitivity::from(strtolower((string) $input->getOption('default-data-sensitivity')));

        $io->text(sprintf('Reading: <info>%s</info>', $filePath));
        if ($dryRun) {
            $io->note('[DRY RUN] No data will be persisted.');
        }

        // ── Load dump ────────────────────────────────────────────────────────
        $content = file_get_contents($filePath);
        if (false === $content) {
            $io->error('Failed to read dump file.');

            return Command::FAILURE;
        }

        // ── Parse reference tables ────────────────────────────────────────────
        $significanceMap = [];
        foreach ($this->parseDumpTable($content, 'significance_tbl') as $row) {
            $significanceMap[(int) $row[0]] = (int) $row[0]; // weight = id (1/2/3)
        }

        $exposureMap = [];
        foreach ($this->parseDumpTable($content, 'exposure_tbl') as $row) {
            $exposureMap[(int) $row[0]] = (int) $row[0]; // weight = id (1..5)
        }

        // asset_type_id → OdinShield AssetType name (category if non-empty, else name)
        $assetTypeNameMap = [];
        foreach ($this->parseDumpTable($content, 'asset_type_tbl') as $row) {
            $id = (int) $row[self::AT_ID];
            $name = (string) $row[self::AT_NAME];
            $cat = (null !== $row[self::AT_CATEGORY]) ? (string) $row[self::AT_CATEGORY] : '';
            $assetTypeNameMap[$id] = ('' !== $cat) ? $cat : $name;
        }

        // group_id → name
        $groupNameMap = [];
        foreach ($this->parseDumpTable($content, 'group_tbl') as $row) {
            $groupNameMap[(int) $row[self::GRP_ID]] = (string) $row[self::GRP_NAME];
        }

        // group_id → [asset_type_id, …]  and reverse: asset_type_id → [group_id, …]
        $groupToAtIds = [];
        $atIdToGroupIds = [];
        foreach ($this->parseDumpTable($content, 'group_tbl_asset_type_tbl') as $row) {
            $gid = (int) $row[0];
            $atid = (int) $row[1];
            $groupToAtIds[$gid][] = $atid;
            $atIdToGroupIds[$atid][] = $gid;
        }

        // legacy user_id → username
        $legacyUserMap = [];
        foreach ($this->parseDumpTable($content, 'user') as $row) {
            $legacyUserMap[(int) $row[self::USR_ID]] = (string) $row[self::USR_USERNAME];
        }

        // vuln_id → [asset_type_id, …]
        $vulnAtMap = [];
        foreach ($this->parseDumpTable($content, 'vulnerability_tbl_asset_type_tbl') as $row) {
            $vulnAtMap[(int) $row[0]][] = (int) $row[1];
        }

        // ── Pre-populate entity caches from DB ────────────────────────────────
        foreach ($this->em->getRepository(Vendor::class)->findAll() as $v) {
            $this->vendorCache[$v->getVendorKey()] = $v;
        }
        foreach ($this->em->getRepository(Software::class)->findAll() as $s) {
            $key = $s->getVendor()->getVendorKey().':'.$s->getProductKey().':'.$s->getVersion();
            $this->softwareCache[$key] = $s;
        }
        foreach ($this->em->getRepository(AssetType::class)->findAll() as $at) {
            $this->assetTypeCache[$at->getName()] = $at;
        }
        foreach ($this->em->getRepository(AssetGroup::class)->findAll() as $ag) {
            $this->assetGroupCache[$ag->getName()] = $ag;
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            if (null !== $u->getUsername()) {
                $this->userCache[$u->getUsername()] = $u;
            }
        }

        // ── Filter vulnerability rows ─────────────────────────────────────────
        $allVulnRows = $this->parseDumpTable($content, 'vulnerability_tbl');
        $filteredRows = array_values(array_filter(
            $allVulnRows,
            fn (array $r): bool => in_array((int) $r[self::VULN_STATUS], $importStatuses, true),
        ));

        $total = ($limit > 0) ? min($limit, count($filteredRows)) : count($filteredRows);
        $io->text(sprintf(
            'Found <info>%d</info> row(s) matching status filter %s (will process: %d).',
            count($filteredRows),
            '['.implode(',', $importStatuses).']',
            $total,
        ));

        $io->progressStart($total);

        // vulnerability entity → [createdAt, updatedAt] strings for post-flush update
        /** @var list<array{vuln: Vulnerability, createdAt: string, updatedAt: string}> $tsUpdates */
        $tsUpdates = [];
        $processed = 0;

        foreach ($filteredRows as $row) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $vuln = $this->processRow(
                $row,
                $vulnAtMap,
                $assetTypeNameMap,
                $atIdToGroupIds,
                $groupNameMap,
                $legacyUserMap,
                $significanceMap,
                $exposureMap,
                $criticality,
                $exposure,
                $dataSensitivity,
                $dryRun,
            );

            if (null !== $vuln && !$dryRun && $preserveTimestamps) {
                $tsUpdates[] = [
                    'vuln' => $vuln,
                    'createdAt' => (string) ($row[self::VULN_CREATED_AT] ?? ''),
                    'updatedAt' => (string) ($row[self::VULN_CHANGED_AT] ?? $row[self::VULN_CREATED_AT] ?? ''),
                ];
            }

            $io->progressAdvance();
            ++$processed;
        }

        $io->progressFinish();

        // ── Flush ─────────────────────────────────────────────────────────────
        if (!$dryRun) {
            $this->em->flush();

            if ($preserveTimestamps && [] !== $tsUpdates) {
                $conn = $this->em->getConnection();
                $backfilled = 0;
                foreach ($tsUpdates as $u) {
                    if ('' === $u['createdAt']) {
                        continue;
                    }
                    $conn->executeStatement(
                        'UPDATE vulnerability SET created_at = :ca, updated_at = :ua WHERE id = :id',
                        ['ca' => $u['createdAt'], 'ua' => $u['updatedAt'], 'id' => $u['vuln']->getId()],
                    );
                    ++$backfilled;
                }
                if ($backfilled > 0) {
                    $io->text(sprintf('Backfilled timestamps for <info>%d</info> vulnerability row(s).', $backfilled));
                }
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $io->table(
            ['Entity', 'Created', 'Reused'],
            [
                ['Vendor', $this->vendorsCreated, count($this->vendorCache) - $this->vendorsCreated],
                ['Software', $this->softwareCreated, count($this->softwareCache) - $this->softwareCreated],
                ['AssetType', $this->assetTypesCreated, count($this->assetTypeCache) - $this->assetTypesCreated],
                ['AssetGroup', $this->assetGroupsCreated, count($this->assetGroupCache) - $this->assetGroupsCreated],
                ['Vulnerability', $this->vulnsCreated, $this->vulnsSkipped],
                ['Occurrence', $this->occurrencesCreated, 0],
            ],
        );

        if ([] !== $this->warnings) {
            $io->warning(array_slice($this->warnings, 0, 20));
            if (count($this->warnings) > 20) {
                $io->text(sprintf('… and %d more warning(s) suppressed.', count($this->warnings) - 20));
            }
        }

        if ($dryRun) {
            $io->note('[DRY RUN] No changes were persisted.');
        } else {
            $io->success(sprintf(
                'Import complete — %d vulnerability/ies and %d occurrence(s) created.',
                $this->vulnsCreated,
                $this->occurrencesCreated,
            ));
        }

        return Command::SUCCESS;
    }

    // ── Core row processing ───────────────────────────────────────────────────

    /**
     * @param list<string|null>     $row
     * @param array<int, list<int>> $vulnAtMap        vuln_id → asset_type_ids
     * @param array<int, string>    $assetTypeNameMap at_id  → OdinShield AssetType name
     * @param array<int, list<int>> $atIdToGroupIds   at_id  → group_ids
     * @param array<int, string>    $groupNameMap     group_id → name
     * @param array<int, string>    $legacyUserMap    user_id → username
     * @param array<int, int>       $significanceMap  id → weight
     * @param array<int, int>       $exposureMap      id → weight
     */
    private function processRow(
        array $row,
        array $vulnAtMap,
        array $assetTypeNameMap,
        array $atIdToGroupIds,
        array $groupNameMap,
        array $legacyUserMap,
        array $significanceMap,
        array $exposureMap,
        AssetCriticality $criticality,
        AssetExposure $exposure,
        DataSensitivity $dataSensitivity,
        bool $dryRun,
    ): ?Vulnerability {
        $legacyId = (int) $row[self::VULN_ID];
        $brand = $this->normalizeVendor(trim((string) ($row[self::VULN_BRAND] ?? '')));
        $softwareName = $this->normalizeSoftwareName(
            trim((string) ($row[self::VULN_SOFTWARE] ?? '')),
            $brand,
        );
        $version = $this->normalizeVersion(
            trim((string) ($row[self::VULN_VERSION] ?? '')),
            $softwareName,
            $brand,
        );

        if ('' === $brand || '' === $softwareName) {
            $this->warnings[] = sprintf('Legacy ID %d: skipped — empty brand or software name.', $legacyId);
            ++$this->vulnsSkipped;

            return null;
        }

        // CVE extraction
        $cveIds = $this->extractCveIds((string) ($row[self::VULN_CVE] ?? ''));
        $primaryCve = $cveIds[0] ?? null;
        $extraCves = array_slice($cveIds, 1);

        // Idempotency: skip if already imported
        if (null !== $primaryCve) {
            if (null !== $this->em->getRepository(Vulnerability::class)->findOneBy(['cveId' => $primaryCve])) {
                ++$this->vulnsSkipped;

                return null;
            }
        } else {
            $title = $this->buildTitle($brand, $softwareName, $version);
            if (null !== $this->em->getRepository(Vulnerability::class)->findOneBy(['title' => $title])) {
                ++$this->vulnsSkipped;

                return null;
            }
        }

        // Severity
        $maxSignif = max(
            $significanceMap[(int) ($row[self::VULN_IMPACT_NET] ?? 1)] ?? 1,
            $significanceMap[(int) ($row[self::VULN_IMPACT_PROC] ?? 1)] ?? 1,
        );
        $internalExposureId = $row[self::VULN_INTERNAL_EXPOSURE] ?? null;
        $maxExposure = max(
            $exposureMap[(int) ($row[self::VULN_EXPOSURE] ?? 1)] ?? 1,
            null !== $internalExposureId ? ($exposureMap[(int) $internalExposureId] ?? 1) : 1,
        );
        $severity = $this->mapSeverity($maxSignif, $maxExposure);
        $status = $this->mapStatus((int) ($row[self::VULN_STATUS] ?? 1));

        // Build Vulnerability
        $vuln = new Vulnerability();
        $vuln->setTitle($this->buildTitle($brand, $softwareName, $version));
        $vuln->setDescription($this->nullIfEmpty($this->unescape((string) ($row[self::VULN_DESCRIPTION] ?? ''))));
        $vuln->setCveId($primaryCve);
        $vuln->setSeverity($severity);
        $vuln->setStatus($status);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setPatchAvailable('1' === (string) ($row[self::VULN_PATCH_AVAILABLE] ?? '0'));
        $vuln->setMitigation($this->nullIfEmpty($this->unescape((string) ($row[self::VULN_MITIGATIONS] ?? ''))));
        $vuln->setRemediationComment($this->nullIfEmpty($this->unescape((string) ($row[self::VULN_COMMENTS] ?? ''))));
        $vuln->setRemediationPriority(RemediationPriority::from($severity->value));

        // discoveredAt from legacy created_at
        $createdAtStr = (string) ($row[self::VULN_CREATED_AT] ?? '');
        $vuln->setDiscoveredAt('' !== $createdAtStr ? new \DateTimeImmutable($createdAtStr) : new \DateTimeImmutable());

        // advisory URL
        $legacySource = (string) ($row[self::VULN_SOURCE] ?? '');
        if (preg_match('#https?://\S+#', $legacySource, $urlM)) {
            $url = rtrim($urlM[0], '.,;)');
            if (strlen($url) <= 500) {
                $vuln->setAdvisoryUrl($url);
            }
        }

        // createdBy / updatedBy
        $createdBy = $this->nullIfEmpty((string) ($row[self::VULN_CREATED_BY] ?? ''));
        $changedBy = $this->nullIfEmpty((string) ($row[self::VULN_CHANGED_BY] ?? ''));
        if (null !== $createdBy) {
            $vuln->setCreatedBy($createdBy);
        }
        if (null !== $changedBy) {
            $vuln->setUpdatedBy($changedBy);
        }

        // responsibleUser — match by username, null if not found
        $ownerId = $row[self::VULN_OWNER];
        if (null !== $ownerId) {
            $username = $legacyUserMap[(int) $ownerId] ?? null;
            if (null !== $username && isset($this->userCache[$username])) {
                $vuln->setResponsibleUser($this->userCache[$username]);
            } elseif (null !== $username) {
                $this->warnings[] = sprintf(
                    'Legacy ID %d: responsible user "%s" not found in OdinShield — set to null.',
                    $legacyId,
                    $username,
                );
            }
        }

        // Software link
        $vendor = $this->findOrCreateVendor($brand, $dryRun);
        $lastReview = $this->nullIfEmpty((string) ($row[self::VULN_LAST_REVIEW] ?? ''));
        $software = $this->findOrCreateSoftware($brand, $softwareName, $version, $vendor, $lastReview, $dryRun);
        $vuln->addSoftware($software);

        // Occurrences: derive AssetGroups via asset_type → group mapping
        $atIds = $vulnAtMap[$legacyId] ?? [];
        $groupIds = [];
        foreach ($atIds as $atId) {
            foreach ($atIdToGroupIds[$atId] ?? [] as $gid) {
                $groupIds[$gid] = $atId; // last atId wins for type resolution
            }
        }

        if ([] === $groupIds) {
            $this->warnings[] = sprintf('Legacy ID %d: no asset type links — assigned to fallback group.', $legacyId);
            $fallbackType = $this->findOrCreateAssetType('Uncategorised', $dryRun);
            $fallbackGroup = $this->findOrCreateAssetGroup('Legacy Import – Uncategorised', $fallbackType, $criticality, $exposure, $dataSensitivity, $dryRun);
            $this->attachOccurrence($vuln, $fallbackGroup, $status, $extraCves, $legacySource);
        } else {
            foreach ($groupIds as $gid => $representativeAtId) {
                $groupName = substr($groupNameMap[$gid] ?? sprintf('Legacy Group %d', $gid), 0, 150);
                $atName = $assetTypeNameMap[$representativeAtId] ?? 'Uncategorised';
                $assetType = $this->findOrCreateAssetType($atName, $dryRun);
                $assetGroup = $this->findOrCreateAssetGroup($groupName, $assetType, $criticality, $exposure, $dataSensitivity, $dryRun);
                $this->attachOccurrence($vuln, $assetGroup, $status, $extraCves, $legacySource);
            }
        }

        if (!$dryRun) {
            $this->em->persist($vuln); // occurrences cascade from here
        }

        ++$this->vulnsCreated;

        return $vuln;
    }

    /** @param list<string> $extraCves */
    private function attachOccurrence(
        Vulnerability $vuln,
        AssetGroup $assetGroup,
        VulnerabilityStatus $status,
        array $extraCves,
        string $legacySource,
    ): void {
        // Avoid duplicate occurrence for the same (vuln, assetGroup) pair within this import run
        foreach ($vuln->getOccurrences() as $existing) {
            if ($existing->getAssetGroup() === $assetGroup) {
                return;
            }
        }

        $occurrence = new VulnerabilityOccurrence($vuln, $assetGroup);
        $occurrence->setStatus($status);

        $noteParts = [];
        if ([] !== $extraCves) {
            $noteParts[] = 'Additional CVEs from legacy: '.implode(', ', $extraCves);
        }
        $src = trim($this->unescape($legacySource));
        if ('' !== $src) {
            $noteParts[] = 'Legacy source: '.$src;
        }
        if ([] !== $noteParts) {
            $occurrence->setNotes(implode("\n", $noteParts));
        }

        $occurrence->recomputeEffectiveRiskScore();
        $vuln->addOccurrence($occurrence); // cascade persist handles persistence
        ++$this->occurrencesCreated;
    }

    // ── Find-or-create helpers ─────────────────────────────────────────────────

    private function findOrCreateVendor(string $brand, bool $dryRun): Vendor
    {
        $key = $this->slugify($brand);
        if (isset($this->vendorCache[$key])) {
            return $this->vendorCache[$key];
        }

        $vendor = new Vendor();
        $vendor->setName(substr($brand, 0, 150));
        $vendor->setVendorKey(substr($key, 0, 150));
        $vendor->setActive(true);
        if (!$dryRun) {
            $this->em->persist($vendor);
        }
        $this->vendorCache[$key] = $vendor;
        ++$this->vendorsCreated;

        return $vendor;
    }

    private function findOrCreateSoftware(
        string $brand,
        string $name,
        string $version,
        Vendor $vendor,
        ?string $lastReview,
        bool $dryRun,
    ): Software {
        $productKey = $this->slugify($name);
        $ver = substr($version, 0, 80);
        $cacheKey = $this->slugify($brand).':'.$productKey.':'.$ver;

        if (isset($this->softwareCache[$cacheKey])) {
            return $this->softwareCache[$cacheKey];
        }

        $software = new Software();
        $software->setName(substr($name, 0, 150));
        $software->setProductKey(substr($productKey, 0, 150));
        $software->setVersion($ver);
        $software->setVendor($vendor);
        $software->setMaintenanceSource(MaintenanceSource::Manual);
        $software->setActive(true);

        if (null !== $lastReview) {
            try {
                $software->setLastReviewedAt(new \DateTimeImmutable($lastReview));
            } catch (\Throwable) {
                // malformed date — skip
            }
        }

        if (!$dryRun) {
            $this->em->persist($software);
        }
        $this->softwareCache[$cacheKey] = $software;
        ++$this->softwareCreated;

        return $software;
    }

    private function findOrCreateAssetType(string $name, bool $dryRun): AssetType
    {
        $name = substr($name, 0, 120);
        if (isset($this->assetTypeCache[$name])) {
            return $this->assetTypeCache[$name];
        }

        $assetType = new AssetType();
        $assetType->setName($name);
        $assetType->setActive(true);
        if (!$dryRun) {
            $this->em->persist($assetType);
        }
        $this->assetTypeCache[$name] = $assetType;
        ++$this->assetTypesCreated;

        return $assetType;
    }

    private function findOrCreateAssetGroup(
        string $name,
        AssetType $assetType,
        AssetCriticality $criticality,
        AssetExposure $exposure,
        DataSensitivity $dataSensitivity,
        bool $dryRun,
    ): AssetGroup {
        $name = substr($name, 0, 150);
        if (isset($this->assetGroupCache[$name])) {
            return $this->assetGroupCache[$name];
        }

        $group = new AssetGroup();
        $group->setName($name);
        $group->setAssetType($assetType);
        $group->setCriticality($criticality);
        $group->setExposure($exposure);
        $group->setDataSensitivity($dataSensitivity);
        $group->setActive(true);
        if (!$dryRun) {
            $this->em->persist($group);
        }
        $this->assetGroupCache[$name] = $group;
        ++$this->assetGroupsCreated;

        return $group;
    }

    // ── Mapping helpers ────────────────────────────────────────────────────────

    private function mapSeverity(int $maxSignif, int $maxExposure): VulnerabilitySeverity
    {
        return match (true) {
            3 === $maxSignif && $maxExposure >= 4 => VulnerabilitySeverity::Critical,
            3 === $maxSignif => VulnerabilitySeverity::High,
            2 === $maxSignif => VulnerabilitySeverity::Medium,
            default => VulnerabilitySeverity::Low,
        };
    }

    private function mapStatus(int $legacyId): VulnerabilityStatus
    {
        return match ($legacyId) {
            3 => VulnerabilityStatus::InProgress,
            4 => VulnerabilityStatus::Closed,      // Abgeschlossen
            5 => VulnerabilityStatus::Accepted,    // verworfen (risk accepted / won't fix)
            6 => VulnerabilityStatus::Resolved,    // umgesetzt (fix implemented)
            default => VulnerabilityStatus::Open,  // 1=neu, 2=in Planung
        };
    }

    private function normalizeVendor(string $brand): string
    {
        return self::VENDOR_ALIASES[strtolower($brand)] ?? $brand;
    }

    private function normalizeSoftwareName(string $name, string $vendor): string
    {
        $lower = strtolower($name);
        if (isset(self::SOFTWARE_CANONICALS[$lower])) {
            return self::SOFTWARE_CANONICALS[$lower];
        }

        // Strip vendor name prefix when it adds no information (e.g. "VMware ESXi" after
        // vendor is already "VMware") — only when the remainder is ≥ 3 characters.
        $vendorPrefix = strtolower($vendor).' ';
        if (str_starts_with($lower, $vendorPrefix)) {
            $stripped = trim(substr($name, strlen($vendorPrefix)));
            if (strlen($stripped) >= 3) {
                return ucfirst($stripped);
            }
        }

        return $name;
    }

    private function normalizeVersion(string $version, string $software, string $vendor): string
    {
        $v = trim($version);
        if ('' === $v) {
            return '';
        }

        // Redundant: version field just repeats the product name
        if (0 === strcasecmp($v, $software)) {
            return '';
        }

        // Strip "version " literal prefix
        $v = (string) preg_replace('/^version\s+/i', '', $v);

        // Strip vendor name prefix (e.g. "Juniper Networks Junos 21.x" → "Junos 21.x")
        $v = $this->stripCaseInsensitivePrefix($v, $vendor.' ');

        // Strip software name prefix (e.g. "Junos 21.x" → "21.x")
        $v = $this->stripCaseInsensitivePrefix($v, $software.' ');

        // Strip "Open Source <word> " prefix (e.g. "Open Source Expat 2.4.1" → "2.4.1")
        $v = (string) preg_replace('/^open\s+source\s+\S+\s+/i', '', $v);

        // Strip ", build <token>" suffix (e.g. "2.4.1, build abc123" → "2.4.1")
        $v = (string) preg_replace('/,?\s*build\s+\S+$/i', '', $v);

        $v = trim($v);

        // Final check: reduced to software name again after stripping?
        if (0 === strcasecmp($v, $software)) {
            return '';
        }

        return $v;
    }

    private function stripCaseInsensitivePrefix(string $str, string $prefix): string
    {
        if ('' === $prefix || !str_starts_with(strtolower($str), strtolower($prefix))) {
            return $str;
        }

        return substr($str, strlen($prefix));
    }

    /** @return list<string> */
    private function extractCveIds(string $raw): array
    {
        preg_match_all('/CVE-\d{4}-\d{4,}/', $raw, $m);
        $valid = [];
        foreach ($m[0] as $cve) {
            if (strlen($cve) <= 20) {
                $valid[] = $cve;
            } else {
                $this->warnings[] = sprintf('CVE ID "%s" exceeds 20 chars — skipped.', $cve);
            }
        }

        return array_values(array_unique($valid));
    }

    private function buildTitle(string $brand, string $software, string $version): string
    {
        return substr(trim(sprintf('%s %s %s', $brand, $software, $version)), 0, 200);
    }

    private function slugify(string $value): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($value))), '-');
    }

    private function nullIfEmpty(string $value): ?string
    {
        $v = trim($value);

        return '' !== $v ? $v : null;
    }

    /**
     * Converts MySQL dump backslash escapes (\\n, \\r, \\t, \\') to actual characters.
     * The dump file stores these as two-char sequences; we restore them for readable output.
     */
    private function unescape(string $value): string
    {
        return strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", "\\'" => "'", '\\\\' => '\\']);
    }

    // ── SQL dump parser ────────────────────────────────────────────────────────

    /**
     * Extracts all data rows for a table from a mysqldump content string.
     *
     * Uses a quote-aware character scan rather than regex so that semicolons
     * inside string fields (e.g. URLs, descriptions) do not terminate the block early.
     *
     * @return list<list<string|null>>
     */
    private function parseDumpTable(string $content, string $table): array
    {
        $marker = 'INSERT INTO `'.$table.'` VALUES';
        $start = strpos($content, $marker);
        if (false === $start) {
            return [];
        }

        $start += strlen($marker);
        $len = strlen($content);
        $i = $start;
        $inString = false;

        while ($i < $len) {
            $ch = $content[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    $i += 2;
                    continue;
                }
                if ("'" === $ch) {
                    $inString = false;
                }
            } else {
                if ("'" === $ch) {
                    $inString = true;
                } elseif (';' === $ch) {
                    break;
                }
            }
            ++$i;
        }

        return $this->tokenizeValues(substr($content, $start, $i - $start));
    }

    /**
     * Parses a mysqldump VALUES block into an array of rows.
     * Handles single-quoted strings with backslash escapes, NULL literals, and integers.
     * Assumes the mysqldump convention that embedded newlines are escaped as \\n / \\r,
     * so no actual unescaped newlines appear inside string fields.
     *
     * @return list<list<string|null>>
     */
    private function tokenizeValues(string $block): array
    {
        $rows = [];
        $i = 0;
        $len = strlen($block);

        while ($i < $len) {
            // Seek to the start of the next row tuple
            while ($i < $len && '(' !== $block[$i]) {
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            ++$i; // consume '('

            $row = [];

            while ($i < $len) {
                // Skip inter-field whitespace
                while ($i < $len && \in_array($block[$i], [' ', "\t", "\n", "\r"], true)) {
                    ++$i;
                }
                if ($i >= $len) {
                    break;
                }

                $ch = $block[$i];

                if (')' === $ch) {
                    ++$i;
                    break; // end of tuple
                }

                if (',' === $ch) {
                    ++$i;
                    continue; // field separator
                }

                if ("'" === $ch) {
                    // Quoted string
                    ++$i;
                    $val = '';
                    while ($i < $len) {
                        $c = $block[$i];
                        if ('\\' === $c && $i + 1 < $len) {
                            $val .= $block[$i + 1];
                            $i += 2;
                        } elseif ("'" === $c) {
                            ++$i;
                            break;
                        } else {
                            $val .= $c;
                            ++$i;
                        }
                    }
                    $row[] = $val;
                } elseif ('N' === $ch && 'NULL' === substr($block, $i, 4)) {
                    $row[] = null;
                    $i += 4;
                } else {
                    // Numeric literal
                    $val = '';
                    while ($i < $len && !in_array($block[$i], [',', ')', ' ', "\t", "\n", "\r"], true)) {
                        $val .= $block[$i];
                        ++$i;
                    }
                    $row[] = $val;
                }
            }

            if ([] !== $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
