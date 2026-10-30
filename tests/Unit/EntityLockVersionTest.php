<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reflection-based guard ensuring all auditable entities carry the optimistic-locking property.
 *
 * Adding a new auditable entity without `lockVersion` (or omitting `#[ORM\Version]`) breaks
 * concurrency safety silently — this test fails immediately so the guarantee is enforced.
 */
final class EntityLockVersionTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function auditableEntities(): iterable
    {
        yield 'Vulnerability' => [Vulnerability::class];
        yield 'VulnerabilityOccurrence' => [VulnerabilityOccurrence::class];
        yield 'Software' => [Software::class];
        yield 'AssetGroup' => [AssetGroup::class];
        yield 'Vendor' => [Vendor::class];
        yield 'User' => [User::class];
        yield 'AssetType' => [AssetType::class];
        yield 'UserGroup' => [UserGroup::class];
    }

    /** @param class-string $class */
    #[DataProvider('auditableEntities')]
    public function testEntityIsAuditable(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $this->assertNotEmpty(
            $reflection->getAttributes(Auditable::class),
            sprintf('%s must carry #[Auditable] for the lockVersion contract to apply.', $class),
        );
    }

    /** @param class-string $class */
    #[DataProvider('auditableEntities')]
    public function testEntityHasLockVersionWithVersionAttribute(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $this->assertTrue(
            $reflection->hasProperty('lockVersion'),
            sprintf('%s must declare a `lockVersion` property for optimistic locking.', $class),
        );

        $property = $reflection->getProperty('lockVersion');

        $this->assertNotEmpty(
            $property->getAttributes(ORM\Version::class),
            sprintf('%s::lockVersion must carry #[ORM\\Version] to enable optimistic locking.', $class),
        );

        $this->assertNotEmpty(
            $property->getAttributes(Ignore::class),
            sprintf('%s::lockVersion must carry #[Ignore] to keep the column out of dh/auditor diffs.', $class),
        );

        $type = $property->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
    }

    /** @param class-string $class */
    #[DataProvider('auditableEntities')]
    public function testEntityExposesGetterAndSetter(string $class): void
    {
        $this->assertTrue(method_exists($class, 'getLockVersion'), sprintf('%s::getLockVersion() missing.', $class));
        $this->assertTrue(method_exists($class, 'setLockVersion'), sprintf('%s::setLockVersion() missing — the form binding requires it.', $class));
    }
}
