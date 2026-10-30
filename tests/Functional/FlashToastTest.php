<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\Software;
use App\Entity\Vendor;
use App\Enum\MaintenanceSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Server-rendered flash toasts split by urgency (#205), announced through permanent live
 * regions (#263).
 *
 * Warnings and errors must stay until closed (WCAG 2.2.1) and interrupt the screen reader;
 * success and info fade after 5 s and are announced politely, so every "saved" does not cut
 * off what the screen reader is reading. "Mark as reviewed" yields both kinds: success for
 * Manual software, a warning for anything else.
 *
 * The announcement itself happens in the browser (toast controller → announceToast()); here
 * only the markup it relies on is checked: the toast hands its level to the controller and
 * carries no live-region attributes of its own, and the layout holds both regions empty.
 */
final class FlashToastTest extends AbstractWebTestCase
{
    public function testLayoutHoldsPermanentEmptyLiveRegions(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $regions = [
            'toast-announcer-polite' => ['status', 'polite'],
            'toast-announcer-assertive' => ['alert', 'assertive'],
        ];
        foreach ($regions as $id => [$role, $live]) {
            $region = $this->client->getCrawler()->filter('#'.$id);
            self::assertCount(1, $region, $id);
            self::assertSame($role, $region->attr('role'), $id);
            self::assertSame($live, $region->attr('aria-live'), $id);
            self::assertSame('false', $region->attr('aria-atomic'), $id);
            self::assertNotNull($region->attr('data-turbo-permanent'), $id);
            self::assertStringContainsString('visually-hidden', (string) $region->attr('class'), $id);
            self::assertSame('', trim($region->html()), $id);
        }
    }

    public function testSuccessToastIsPoliteAndHidesItself(): void
    {
        $this->loginAsAdmin();
        $software = $this->software(MaintenanceSource::Manual);

        $this->markReviewed($software);

        $toast = $this->client->getCrawler()->filter('.toast.toast--success');
        self::assertCount(1, $toast);
        self::assertSame('success', $toast->attr('data-toast-level-value'));
        self::assertNull($toast->attr('role'));
        self::assertNull($toast->attr('aria-live'));
        self::assertNull($toast->attr('data-bs-autohide'));
    }

    public function testWarningToastIsAssertiveAndStaysUntilClosed(): void
    {
        $this->loginAsAdmin();
        $software = $this->software(MaintenanceSource::Manual);

        // Take the token while the button is still offered, then switch the source so the
        // server answers with the warning flash.
        $crawler = $this->client->request('GET', '/software');
        $form = $crawler->filter(\sprintf('form[action="/software/%s/mark-reviewed"]', $software->getUuid()))->form();
        $this->em()->createQuery('UPDATE '.Software::class.' s SET s.maintenanceSource = :import WHERE s.id = :id')
            ->setParameter('import', MaintenanceSource::Import)
            ->setParameter('id', $software->getId())
            ->execute();
        $this->client->submit($form);
        $this->client->followRedirect();

        $toast = $this->client->getCrawler()->filter('.toast.toast--warning');
        self::assertCount(1, $toast);
        self::assertSame('warning', $toast->attr('data-toast-level-value'));
        self::assertNull($toast->attr('role'));
        self::assertNull($toast->attr('aria-live'));
        self::assertSame('false', $toast->attr('data-bs-autohide'));
    }

    private function markReviewed(Software $software): void
    {
        $crawler = $this->client->request('GET', '/software');
        $form = $crawler->filter(\sprintf('form[action="/software/%s/mark-reviewed"]', $software->getUuid()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
    }

    private function software(MaintenanceSource $source): Software
    {
        $em = $this->em();
        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Toasted');
        $software->setVendor($vendor);
        $software->setProductKey('toasted');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $software->setMaintenanceSource($source);
        $em->persist($software);
        $em->flush();

        return $software;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
