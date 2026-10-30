<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\Software;
use App\Entity\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class SoftwareFormLiveComponentConflictTest extends AbstractWebTestCase
{
    use InteractsWithLiveComponents;

    public function testConflictPathReBindsToFreshEntityWithoutEntityManagerErrors(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Original Name');
        $software->setVendor($vendor);
        $software->setProductKey('orig');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $em->persist($software);
        $em->flush();

        $component = $this->createLiveComponent(
            'SoftwareForm',
            ['initialFormData' => $software, 'isNew' => false, 'returnParams' => []],
            $this->client,
        );

        // Simulate concurrent edit: bump version + rename via raw SQL.
        $em->getConnection()->executeStatement(
            'UPDATE software SET lock_version = lock_version + 1, name = ? WHERE id = ?',
            ['Name From Other User', $software->getId()],
        );

        // Trigger the save action with the user's changes — this should hit the conflict
        // branch on flush, swap initialFormData to the fresh entity, and re-render the
        // component without throwing "Entity must be managed".
        $component->submitForm([
            'software' => [
                'name' => 'Name From Conflicted User',
                'vendor' => (string) $vendor->getId(),
                'productKey' => 'orig',
                'version' => '1.0.0',
                'maintenanceSource' => 'manual',
                'active' => '1',
            ],
        ], 'save');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Name From Other User', (string) $component->render());
        $this->assertStringNotContainsString('must be managed', (string) $component->render());
    }
}
