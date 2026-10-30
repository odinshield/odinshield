<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-014: an expired session must not inject the login page into a Turbo frame/modal. A genuine
 * navigation still gets the 302 redirect; Turbo/fetch requests get a bare 401 + X-Login-Redirect.
 */
final class TurboAwareEntryPointTest extends WebTestCase
{
    public function testGenuineNavigationRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTurboStreamRequestGets401WithLoginHeader(): void
    {
        $this->assertUnauthorized(static::createClient(), ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
    }

    public function testTurboFrameRequestGets401(): void
    {
        $this->assertUnauthorized(static::createClient(), ['HTTP_TURBO_FRAME' => 'content']);
    }

    public function testFetchRequestGets401(): void
    {
        // A raw fetch() (e.g. the modal loader) is tagged Sec-Fetch-Mode: cors by the browser.
        $this->assertUnauthorized(static::createClient(), ['HTTP_SEC_FETCH_MODE' => 'cors']);
    }

    public function testTopLevelNavigateModeStillRedirects(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', [], [], ['HTTP_SEC_FETCH_MODE' => 'navigate']);

        self::assertResponseRedirects();
    }

    /**
     * @param array<string, string> $server
     */
    private function assertUnauthorized(KernelBrowser $client, array $server): void
    {
        $client->request('GET', '/', [], [], $server);

        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('X-Login-Redirect'));
    }
}
