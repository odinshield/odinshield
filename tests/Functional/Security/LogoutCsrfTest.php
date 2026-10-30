<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use App\Tests\Functional\AbstractWebTestCase;

/**
 * SEC-020 (#195) — logout is POST-only and CSRF-protected. A GET link was prefetched by Turbo on
 * hover and ended the session; a foreign page could do the same with `<img src=".../logout">`.
 *
 * The rendered token is the stateless placeholder (`csrf-token`); the `csrf-protection` Stimulus
 * controller swaps it in the browser. Here the same-origin `Sec-Fetch-Site` header is the valid
 * signal, a `cross-site` one stands in for a forged request.
 */
final class LogoutCsrfTest extends AbstractWebTestCase
{
    public function testGetDoesNotLogOut(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/logout', [], [], ['HTTP_SEC_PURPOSE' => 'prefetch']);

        $this->assertResponseStatusCodeSame(405);
        $this->assertStillLoggedIn();
    }

    public function testPostWithoutTokenDoesNotLogOut(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/logout', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertStillLoggedIn();
    }

    public function testCrossSitePostDoesNotLogOut(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertStillLoggedIn();
    }

    public function testNavbarFormLogsOut(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/logout"]');
        $this->assertCount(1, $form, 'The navbar must carry exactly one logout form.');
        $this->assertSame('post', $form->attr('method'));

        $this->client->request(
            'POST',
            '/logout',
            ['_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );

        $this->assertResponseRedirects('/login');
        $this->client->request('GET', '/settings');
        $this->assertResponseRedirects('/login');
    }

    private function assertStillLoggedIn(): void
    {
        $this->client->request('GET', '/settings');
        $this->assertResponseIsSuccessful();
    }
}
