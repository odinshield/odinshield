<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class ManualControllerTest extends AbstractWebTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validSlugProvider(): iterable
    {
        yield 'erste-schritte' => ['erste-schritte'];
        yield 'schwachstellen' => ['schwachstellen'];
        yield 'inventar' => ['inventar'];
        yield 'team' => ['team'];
        yield 'einstellungen' => ['einstellungen'];
        yield 'faq' => ['faq'];
        yield 'glossar' => ['glossar'];
        yield 'tipps' => ['tipps'];
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->client->request('GET', '/manual/erste-schritte');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testContextRouteRedirectsToErsteSchritteByDefault(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/manual');

        self::assertResponseRedirects('/manual/erste-schritte');
    }

    public function testContextRouteMapsKnownRouteToChapter(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/manual?from=app_vulnerability_index');

        // The test admin's locale is English, so the anchor is the English heading's slug (#212).
        self::assertResponseRedirects('/manual/schwachstellen#work-efficiently-filter--search');
    }

    public function testContextRouteFallsBackForUnknownRoute(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/manual?from=app_unknown_route');

        self::assertResponseRedirects('/manual/erste-schritte');
    }

    #[DataProvider('validSlugProvider')]
    public function testValidSlugReturns200(string $slug): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/manual/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main.manual-content-inner');
        self::assertSelectorExists('aside.manual-drawer');
        self::assertSelectorExists('nav.manual-breadcrumb-bar');
        self::assertGreaterThan(0, $crawler->filter('h1')->count(), 'Each manual page must render an h1.');
    }

    public function testInvalidSlugReturns404(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/manual/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHeadingPermalinksAreRendered(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/manual/schwachstellen');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a.manual-heading-anchor')->count(), 'Heading permalinks should be added by HeadingPermalinkExtension.');
    }

    public function testNavigationDrawerIsRenderedWithAllChapters(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/manual/erste-schritte');

        $links = $crawler->filter('nav .manual-nav-link')->each(fn ($node) => $node->attr('href'));

        foreach (['erste-schritte', 'schwachstellen', 'inventar', 'team', 'einstellungen', 'faq', 'glossar', 'tipps'] as $slug) {
            self::assertContains('/manual/'.$slug, $links, sprintf('Drawer must link to /manual/%s', $slug));
        }
    }

    public function testInternalMarkdownLinksTargetValidSlugs(): void
    {
        $this->loginAsAdmin();
        $validSlugs = ['erste-schritte', 'schwachstellen', 'inventar', 'team', 'einstellungen', 'faq', 'glossar', 'tipps'];

        foreach ($validSlugs as $slug) {
            $crawler = $this->client->request('GET', '/manual/'.$slug);
            self::assertResponseIsSuccessful();

            $bodyLinks = $crawler->filter('.manual-body a')->each(fn ($node) => $node->attr('href'));

            foreach ($bodyLinks as $href) {
                if (null === $href || str_starts_with((string) $href, '#') || str_starts_with((string) $href, '/') || str_starts_with((string) $href, 'http')) {
                    continue;
                }

                // Strip anchor fragment if present (e.g. "glossar#effektives-risiko" → "glossar")
                $bare = preg_replace('/#.*$/', '', (string) $href);

                self::assertContains(
                    $bare,
                    $validSlugs,
                    sprintf('Manual page "%s" links to invalid slug "%s"', $slug, $href),
                );
            }
        }
    }
}
