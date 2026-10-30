<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Software;

use App\Entity\Software;
use App\Entity\Vendor;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Saving a new software without a vendor used to pass validation and die on the NOT NULL
 * constraint of `software.vendor_id` (500 on the demo instance, 2026-09-18).
 */
final class SoftwareFormVendorRequiredTest extends AbstractWebTestCase
{
    use InteractsWithLiveComponents;

    public function testSaveWithoutVendorIsRejectedByValidationNotByTheDatabase(): void
    {
        $this->loginAsAdmin();

        $component = $this->createLiveComponent('SoftwareForm', ['isNew' => true, 'returnParams' => []], $this->client);

        $rejected = null;
        try {
            $component->submitForm([
                'software' => [
                    'name' => 'JunOS',
                    'vendor' => '',
                    'productKey' => 'junos',
                    'version' => '23.4R2-S6',
                    'maintenanceSource' => 'import',
                    'active' => '1',
                ],
            ], 'save');
        } catch (UnprocessableEntityHttpException $e) {
            $rejected = $e;
        }

        self::assertNotNull($rejected, 'An empty vendor must fail validation (422), not reach the database.');

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        self::assertNull($em->getRepository(Software::class)->findOneBy(['productKey' => 'junos']));
    }

    public function testClearingTheVendorOfExistingSoftwareIsRejectedByValidation(): void
    {
        $this->loginAsAdmin();
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $vendor = (new Vendor())->setName('Juniper')->setVendorKey('juniper');
        $em->persist($vendor);
        $software = new Software();
        $software->setName('Junos Space')->setVendor($vendor)->setProductKey('junos_space')->setVersion('24.1');
        $em->persist($software);
        $em->flush();

        $component = $this->createLiveComponent('SoftwareForm', ['initialFormData' => $software, 'isNew' => false, 'returnParams' => []], $this->client);

        $rejected = null;
        try {
            $component->submitForm([
                'software' => [
                    'name' => 'Junos Space',
                    'vendor' => '',
                    'productKey' => 'junos_space',
                    'version' => '24.1',
                    'maintenanceSource' => 'import',
                    'active' => '1',
                ],
            ], 'save');
        } catch (UnprocessableEntityHttpException $e) {
            $rejected = $e;
        }

        self::assertNotNull($rejected, 'An emptied vendor must fail validation (422), not crash in the setter.');
    }
}
