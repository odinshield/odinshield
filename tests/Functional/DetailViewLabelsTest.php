<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\Vendor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only views say what their buttons do (#207, UX-45).
 *
 * The detail modal of the index pages and the detail pages only show a record, so there is
 * nothing to cancel: the modal closes, the page goes back to the list. The modal is named by its
 * title for assistive technology. Vendor stands in for all seven entities — the modal is one
 * shared include, and every show page uses the same footer.
 */
final class DetailViewLabelsTest extends AbstractWebTestCase
{
    public function testDetailModalIsNamedByItsTitleAndCloses(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/vendor');
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#vendorDetailModal');
        self::assertCount(1, $modal);
        $titleId = (string) $modal->attr('aria-labelledby');
        self::assertNotSame('', $titleId);
        self::assertCount(1, $modal->filter('#'.$titleId.'.modal-title'));
        self::assertSame('Close', trim($modal->filter('.modal-footer button[data-bs-dismiss="modal"]')->text()));
    }

    public function testDetailPageGoesBackToTheList(): void
    {
        $this->loginAsAdmin();
        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $this->em()->persist($vendor);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/vendor/'.$vendor->getUuid());
        self::assertResponseIsSuccessful();

        $footer = $crawler->filter('.card-footer a.btn-outline-secondary');
        self::assertSame('Back to list', trim($footer->text()));
        self::assertStringStartsWith('/vendor', (string) $footer->attr('href'));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
