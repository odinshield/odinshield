<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Security;

use App\Tests\Functional\AbstractWebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * I-01 (#200): the dh/auditor viewer routes are not registered at all — not merely blocked by
 * `viewer: false` — so a config change can never expose the audit tables past the app's own
 * access checks. Even an admin gets a plain 404 on the bundle's paths.
 */
final class AuditorViewerRoutesTest extends AbstractWebTestCase
{
    public function testBundleViewerRoutesAreNotRegistered(): void
    {
        /** @var RouterInterface $router */
        $router = $this->client->getContainer()->get('router');

        $bundleRoutes = array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => str_starts_with($name, 'dh_auditor_'),
        );

        self::assertSame([], array_values($bundleRoutes));
    }

    public function testBundleViewerPathsAnswer404EvenForAnAdmin(): void
    {
        $this->loginAsAdmin();

        foreach (['/audit/transaction/abc123', '/audit/App-Entity-User/1'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }
}
