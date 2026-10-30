<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Software;

use App\Entity\Software;
use App\Entity\Vendor;
use App\Enum\MaintenanceSource;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #319 (§4.4 of the ledger plan): the CSAF import matches Import-maintained software without an
 * asset group but skips every hit — until now with a log notice nobody reads in production. The
 * software list and the detail page say it where the user looks.
 */
final class ImportWithoutAssetGroupMarkerTest extends AbstractWebTestCase
{
    private const string MARKER = 'No asset group assigned, the import creates no vulnerabilities';

    public function testListMarksOnlyImportSoftwareWithoutAssetGroup(): void
    {
        $orphan = $this->persistSoftware('OrphanApp', MaintenanceSource::Import);
        $grouped = $this->persistSoftware('GroupedApp', MaintenanceSource::Import);
        // Manual software without a group is not the importer's business — no marker.
        $this->persistSoftware('ManualApp', MaintenanceSource::Manual);
        $this->createAssetGroup()->addSoftware($grouped);
        $this->em()->flush();

        $this->loginAsSoftwareOwner();
        $crawler = $this->client->request('GET', '/software');

        self::assertResponseIsSuccessful();
        $marked = $crawler->filter(\sprintf('tbody svg[aria-label="%s"]', self::MARKER));
        self::assertCount(1, $marked);
        self::assertStringContainsString($orphan->getName(), $marked->closest('tr')?->text() ?? '');
    }

    public function testDetailPageSaysIt(): void
    {
        $orphan = $this->persistSoftware('OrphanApp', MaintenanceSource::Import);

        $this->loginAsSoftwareOwner();
        $this->client->request('GET', '/software/'.$orphan->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#tab-software-detail', self::MARKER);
    }

    private function persistSoftware(string $name, MaintenanceSource $source): Software
    {
        $vendor = $this->em()->getRepository(Vendor::class)->findOneBy(['vendorKey' => 'acme']);
        if (null === $vendor) {
            $vendor = new Vendor();
            $vendor->setName('Acme');
            $vendor->setVendorKey('acme');
            $vendor->setActive(true);
            $this->em()->persist($vendor);
        }

        $software = new Software();
        $software->setName($name);
        $software->setProductKey(strtolower($name));
        $software->setVersion('1.0');
        $software->setVendor($vendor);
        $software->setActive(true);
        $software->setMaintenanceSource($source);
        $this->em()->persist($software);
        $this->em()->flush();

        return $software;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
