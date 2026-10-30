<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

/**
 * The rendered import map carries no es-module-shims fallback.
 *
 * AssetMapper's default polyfill injects a loader for ga.jspm.io into every page that
 * calls importmap(). The CSP (script-src 'self') blocks that host anyway, and every
 * supported browser has native import maps, so the snippet is dead code that only puts
 * a third-party URL into the HTML. `importmap_polyfill: false` removes it; this test
 * covers the three layouts that render the import map.
 */
final class ImportmapPolyfillTest extends AbstractWebTestCase
{
    public function testLoginPageHasNoPolyfillLoader(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertNoPolyfillLoader();
    }

    public function testAppLayoutHasNoPolyfillLoader(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertNoPolyfillLoader();
    }

    public function testManualLayoutHasNoPolyfillLoader(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/manual');
        if ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }

        self::assertResponseIsSuccessful();
        $this->assertNoPolyfillLoader();
    }

    private function assertNoPolyfillLoader(): void
    {
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('<script type="importmap"', $html, 'The page must still render the import map.');
        self::assertStringNotContainsString('es-module-shims', $html);
        self::assertStringNotContainsString('ga.jspm.io', $html);
    }
}
