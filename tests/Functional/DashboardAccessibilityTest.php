<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\CsafProviderState;
use App\Entity\Software;
use App\Entity\Vendor;
use App\Enum\CsafProviderStatus;
use App\Enum\MaintenanceSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dashboard accessibility (#207, UX-39 and UX-46).
 *
 * The monthly chart is a canvas — one image to a screen reader — so its figures are repeated in a
 * visually hidden table. The headings form one outline without jumps: banners and card titles are
 * h2 under the page's h1. A software that was never reviewed says so instead of showing a dash.
 * Colour contrast and the redraw on a theme switch are browser-side and not checked here.
 */
final class DashboardAccessibilityTest extends AbstractWebTestCase
{
    public function testChartHasATextAlternativeAndAHiddenTable(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $canvas = $crawler->filter('[data-controller="chart-theme"] canvas');
        self::assertCount(1, $canvas);
        self::assertSame('img', $canvas->attr('role'));
        // The card heading already names the chart; the label says what kind it is and where the figures are.
        self::assertStringContainsString('Bar chart', (string) $canvas->attr('aria-label'));

        $table = $crawler->filter('[data-controller="chart-theme"] table.visually-hidden');
        self::assertCount(1, $table);
        $year = (int) date('Y');
        self::assertSame(['Month', (string) ($year - 1), (string) $year], $table->filter('thead th')->each(static fn ($th): string => trim($th->text())));
        self::assertCount(12, $table->filter('tbody tr'));
        self::assertSame('January', trim($table->filter('tbody tr')->first()->filter('th')->text()));
    }

    public function testHeadingsHaveNoJumpsEvenWithBothBanners(): void
    {
        // A disrupted source and unassigned software bring up both banners for the ISB/admin.
        $state = new CsafProviderState('prov-a', 'Provider A', 'https://example.test/pm.json');
        $state->setStatus(CsafProviderStatus::AutoPaused);
        $state->setLastSuccessAt(new \DateTimeImmutable('-10 days'));
        $state->setLastErrorAt(new \DateTimeImmutable());
        $state->setConsecutiveFailures(3);
        $this->em()->persist($state);
        $this->software(null);

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.alert .alert-title'), 'Both banners must be present for this check to mean anything.');

        // Closed modals are hidden from the accessibility tree, and an open one is its own dialog
        // context — their titles are not part of the page outline.
        $levels = $crawler->filter('h1, h2, h3, h4, h5, h6')
            ->reduce(static fn ($h): bool => null === $h->closest('.modal'))
            ->each(static fn ($h): int => (int) substr($h->nodeName(), 1));
        self::assertSame(1, $levels[0], 'The outline starts with the page h1.');
        foreach (array_slice($levels, 1, null, true) as $i => $level) {
            self::assertLessThanOrEqual($levels[$i - 1] + 1, $level, sprintf('Heading %d jumps from h%d to h%d.', $i, $levels[$i - 1], $level));
        }
    }

    public function testNeverReviewedSoftwareSaysSo(): void
    {
        $this->software(null);
        $this->loginAsSoftwareOwner();

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $item = $crawler->filter('.list-group-item')->reduce(static fn ($li): bool => str_contains($li->text(), 'Never Reviewed Product'));
        self::assertCount(1, $item);
        self::assertStringContainsString('Never reviewed', $item->text());
    }

    private function software(?\DateTimeImmutable $lastReviewedAt): Software
    {
        $em = $this->em();
        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Never Reviewed Product');
        $software->setVendor($vendor);
        $software->setProductKey('never_reviewed_product');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $software->setMaintenanceSource(MaintenanceSource::Manual);
        $software->setLastReviewedAt($lastReviewedAt);
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
