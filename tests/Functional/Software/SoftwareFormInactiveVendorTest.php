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
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * #213 (UX-25): the vendor field only listed active vendors. A software whose vendor had been
 * deactivated rendered an empty vendor field and could not be saved — setting the EOL date of a
 * retired product meant picking some other vendor first.
 */
final class SoftwareFormInactiveVendorTest extends AbstractWebTestCase
{
    use InteractsWithLiveComponents;

    public function testEditFormKeepsTheInactiveCurrentVendorSelectedAndMarked(): void
    {
        $this->loginAsAdmin();
        [$software, $inactive] = $this->seedSoftwareWithInactiveVendor();

        $crawler = $this->client->request('GET', '/software/'.$software->getUuid().'/edit');

        $this->assertResponseIsSuccessful();
        $selected = $crawler->filter('select[name="software[vendor]"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame((string) $inactive->getId(), $selected->attr('value'));
        self::assertSame('Legacy Corp (Inactive)', $selected->text());
        self::assertSame('true', $selected->attr('data-inactive'));
    }

    public function testOtherInactiveVendorsStayOutOfTheChoice(): void
    {
        $this->loginAsAdmin();
        [$software] = $this->seedSoftwareWithInactiveVendor();

        $crawler = $this->client->request('GET', '/software/'.$software->getUuid().'/edit');

        $options = $crawler->filter('select[name="software[vendor]"] option')->each(static fn ($o) => $o->text());
        self::assertContains('Acme', $options);
        self::assertNotContains('Defunct Ltd (Inactive)', $options);
        self::assertNotContains('Defunct Ltd', $options);
    }

    public function testNewSoftwareOffersOnlyActiveVendors(): void
    {
        $this->loginAsAdmin();
        $this->seedSoftwareWithInactiveVendor();

        $crawler = $this->client->request('GET', '/software/new');

        $this->assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="software[vendor]"] option')->each(static fn ($o) => $o->text());
        self::assertContains('Acme', $options);
        self::assertNotContains('Legacy Corp (Inactive)', $options);
        self::assertNotContains('Legacy Corp', $options);
    }

    public function testSoftwareWithInactiveVendorCanBeSaved(): void
    {
        $this->loginAsAdmin();
        [$software, $inactive] = $this->seedSoftwareWithInactiveVendor();

        $component = $this->createLiveComponent('SoftwareForm', ['initialFormData' => $software, 'isNew' => false, 'returnParams' => []], $this->client);
        $component->submitForm([
            'software' => [
                'name' => 'Legacy Suite',
                'vendor' => (string) $inactive->getId(),
                'productKey' => 'legacy_suite',
                'version' => '4.2',
                'eolDate' => '12/31/2026',
                'maintenanceSource' => 'manual',
            ],
        ], 'save');

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reloaded = $em->getRepository(Software::class)->findOneBy(['productKey' => 'legacy_suite']);
        self::assertNotNull($reloaded);
        self::assertSame('2026-12-31', $reloaded->getEolDate()?->format('Y-m-d'));
        self::assertSame('Legacy Corp', $reloaded->getVendor()->getName());
    }

    /**
     * @return array{Software, Vendor}
     */
    private function seedSoftwareWithInactiveVendor(): array
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $active = (new Vendor())->setName('Acme')->setVendorKey('acme');
        $inactive = (new Vendor())->setName('Legacy Corp')->setVendorKey('legacy_corp')->setActive(false);
        $otherInactive = (new Vendor())->setName('Defunct Ltd')->setVendorKey('defunct_ltd')->setActive(false);
        $em->persist($active);
        $em->persist($inactive);
        $em->persist($otherInactive);

        $software = new Software();
        $software->setName('Legacy Suite')->setVendor($inactive)->setProductKey('legacy_suite')->setVersion('4.2')->setActive(false);
        $em->persist($software);
        $em->flush();

        return [$software, $inactive];
    }
}
