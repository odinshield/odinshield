<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Validator;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use App\Validator\Constraints\AssetGroupsCoveredBySoftware;
use App\Validator\Constraints\AssetGroupsCoveredBySoftwareValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<AssetGroupsCoveredBySoftwareValidator>
 */
final class AssetGroupsCoveredBySoftwareValidatorTest extends ConstraintValidatorTestCase
{
    /**
     * Whether the entity manager reports occurrences as already persisted — the switch
     * between "new, so subject to the rule" and "grandfathered".
     */
    private bool $everythingIsPersisted = false;

    protected function createValidator(): ConstraintValidatorInterface
    {
        // A stub, not a mock: the validator's use of the entity manager is an input to the
        // decision, not a behaviour worth asserting.
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('contains')->willReturnCallback(fn (): bool => $this->everythingIsPersisted);

        return new AssetGroupsCoveredBySoftwareValidator($em);
    }

    public function testNoSoftwareMeansFreeChoice(): void
    {
        $vuln = $this->vulnerability();
        $vuln->addAssetGroup($this->assetGroup('Anything at all'));

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->assertNoViolation();
    }

    public function testAssetGroupDerivedFromSoftwareIsValid(): void
    {
        $group = $this->assetGroup('Internet Firewall');
        $vuln = $this->vulnerability();
        $vuln->addSoftware($this->software('FortiOS', $group));
        $vuln->addAssetGroup($group);

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->assertNoViolation();
    }

    public function testAssetGroupNotDerivedFromSoftwareIsRejected(): void
    {
        $covered = $this->assetGroup('Internet Firewall');
        $uncovered = $this->assetGroup('Database Cluster');

        $vuln = $this->vulnerability();
        $vuln->addSoftware($this->software('FortiOS', $covered));
        $vuln->addAssetGroup($uncovered);

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->buildViolation('vulnerability.asset_groups.not_covered_by_software')
            ->setParameter('%asset_group%', 'Database Cluster')
            ->atPath('property.path.assetGroups')
            ->assertRaised();
    }

    public function testSoftwareWithoutAnyAssetGroupIsReportedOnTheSoftwareField(): void
    {
        $vuln = $this->vulnerability();
        $vuln->addSoftware($this->software('Homeless Widget'));
        $vuln->addAssetGroup($this->assetGroup('Internet Firewall'));

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->buildViolation('vulnerability.software.no_asset_groups')
            ->atPath('property.path.software')
            ->assertRaised();
    }

    public function testAlreadyPersistedOccurrenceIsGrandfathered(): void
    {
        $this->everythingIsPersisted = true;

        $covered = $this->assetGroup('Internet Firewall');
        $uncovered = $this->assetGroup('Database Cluster');

        $vuln = $this->vulnerability();
        $vuln->addSoftware($this->software('FortiOS', $covered));
        $vuln->addAssetGroup($uncovered);

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->assertNoViolation();
    }

    /**
     * A retired asset group must not seed a *new* occurrence (audit L11), so it is no
     * candidate — even though its inventory link still exists.
     */
    public function testRetiredAssetGroupIsNoCandidate(): void
    {
        $retired = $this->assetGroup('Decommissioned Rack');
        $retired->setActive(false);

        $vuln = $this->vulnerability();
        $vuln->addSoftware($this->software('FortiOS', $retired));
        $vuln->addAssetGroup($retired);

        $this->validator->validate($vuln, new AssetGroupsCoveredBySoftware());

        $this->buildViolation('vulnerability.software.no_asset_groups')
            ->atPath('property.path.software')
            ->assertRaised();
    }

    private function vulnerability(): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setTitle('Test Vulnerability');
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));

        return $vuln;
    }

    private function assetGroup(string $name): AssetGroup
    {
        $assetType = new AssetType();
        $assetType->setName('Server');
        $assetType->setActive(true);

        $group = new AssetGroup();
        $group->setName($name);
        $group->setAssetType($assetType);
        $group->setActive(true);

        return $group;
    }

    private function software(string $name, ?AssetGroup ...$assetGroups): Software
    {
        $vendor = new Vendor();
        $vendor->setName('Acme Corp');
        $vendor->setVendorKey('acme');

        $software = new Software();
        $software->setName($name);
        $software->setProductKey(strtolower(str_replace(' ', '_', $name)));
        $software->setVersion('1.0.0');
        $software->setVendor($vendor);
        $software->setActive(true);

        foreach ($assetGroups as $group) {
            if (null !== $group) {
                $group->addSoftware($software);
            }
        }

        return $software;
    }
}
