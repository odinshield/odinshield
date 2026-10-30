<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

/**
 * Manual shell accessibility (#207, UX-44) — the server-side half.
 *
 * Focus trap, focus return, Escape and the inert drawer are browser behaviour (manual_search
 * and manual_nav controllers). Checked here is the markup they rely on and what is decided on
 * the server: the status regions, a shortcut hint left to the client instead of a fixed
 * "Cmd+K", an unavailable hint without a developer command, and one reading order for the
 * drawer and the previous/next links.
 */
final class ManualAccessibilityTest extends AbstractWebTestCase
{
    private const array READING_ORDER = [
        'erste-schritte', 'schwachstellen', 'inventar', 'einstellungen', 'team', 'faq', 'glossar', 'tipps',
    ];

    public function testSearchMarkupLeavesTheShortcutToTheClientAndHasAStatusRegion(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/manual/erste-schritte');
        self::assertResponseIsSuccessful();

        $opener = $crawler->filter('[data-manual-search-target="opener"]');
        self::assertCount(1, $opener);
        self::assertStringNotContainsString('Cmd+K', (string) $opener->attr('title'));

        $body = $crawler->filter('body');
        self::assertSame('Ctrl+K', $body->attr('data-manual-search-shortcut-value'));
        self::assertSame('Cmd+K', $body->attr('data-manual-search-shortcut-mac-value'));
        self::assertStringNotContainsString('make', (string) $body->attr('data-manual-search-unavailable-hint-value'));

        foreach (['[data-manual-search-target="status"]', '#manual-copy-status'] as $selector) {
            $region = $crawler->filter($selector);
            self::assertCount(1, $region, $selector);
            self::assertSame('status', $region->attr('role'), $selector);
            self::assertSame('', trim($region->text()), $selector);
        }
    }

    public function testDrawerAndPreviousNextFollowOneReadingOrder(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/manual/erste-schritte');
        $drawer = $crawler->filter('.manual-drawer nav a.manual-nav-link')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertSame(array_map(static fn (string $s): string => '/manual/'.$s, self::READING_ORDER), $drawer);

        foreach (self::READING_ORDER as $i => $slug) {
            $page = $this->client->request('GET', '/manual/'.$slug);
            $prev = $page->filter('a.manual-pagination-link.prev');
            $expectedPrev = self::READING_ORDER[$i - 1] ?? null;
            if (null === $expectedPrev) {
                self::assertCount(0, $prev, $slug);
            } else {
                self::assertSame('/manual/'.$expectedPrev, $prev->attr('href'), $slug);
            }
            $expectedNext = self::READING_ORDER[$i + 1] ?? null;
            if (null !== $expectedNext) {
                self::assertSame('/manual/'.$expectedNext, $page->filter('a.manual-pagination-link.next')->attr('href'), $slug);
            }
        }
    }
}
