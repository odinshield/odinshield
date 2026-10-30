<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which client IP Symfony derives behind a front proxy (#199).
 *
 * The chain is the one measured on Demo: external Caddy (a private front-proxy address) → Traefik (172.22.1.8,
 * trusts the front proxy and appends it) → FrankenPHP. Login throttling and the audit trail key
 * on `Request::getClientIp()`, so the TRUSTED_PROXIES value in compose.deploy.yaml decides
 * whether every user shares one IP.
 */
final class TrustedProxyChainTest extends TestCase
{
    private const string TRAEFIK = '172.22.1.8';
    private const string FRONT_PROXY = '10.20.0.2';
    private const string CLIENT = '203.0.113.7';

    /** Mirrors framework.trusted_headers in config/packages/framework.yaml. */
    private const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_HOST;

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testRemoteAddrAloneCollapsesEveryClientOntoTheFrontProxy(): void
    {
        $request = $this->requestFromTraefik(self::CLIENT.', '.self::FRONT_PROXY);
        $this->trust(['REMOTE_ADDR'], $request);

        self::assertSame(self::FRONT_PROXY, $request->getClientIp());
    }

    public function testTraefikSubnetAndFrontProxyYieldTheRealClient(): void
    {
        $request = $this->requestFromTraefik(self::CLIENT.', '.self::FRONT_PROXY);
        $this->trust(['172.22.0.0/16', self::FRONT_PROXY], $request);

        self::assertSame(self::CLIENT, $request->getClientIp());
    }

    public function testForgedLeftMostEntryIsIgnored(): void
    {
        // A client-supplied X-Forwarded-For that slipped through sits left of the real client;
        // Symfony reads from the right and stops at the first untrusted hop.
        $request = $this->requestFromTraefik('198.51.100.66, '.self::CLIENT.', '.self::FRONT_PROXY);
        $this->trust(['172.22.0.0/16', self::FRONT_PROXY], $request);

        self::assertSame(self::CLIENT, $request->getClientIp());
    }

    private function requestFromTraefik(string $forwardedFor): Request
    {
        return Request::create('https://demo.example/', server: [
            'REMOTE_ADDR' => self::TRAEFIK,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
    }

    /**
     * @param list<string> $proxies
     */
    private function trust(array $proxies, Request $request): void
    {
        // Kernel::preBoot() resolves REMOTE_ADDR against $_SERVER, i.e. the request that boots
        // the worker — here the same Traefik connection.
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $request->server->get('REMOTE_ADDR');
        try {
            Request::setTrustedProxies($proxies, self::TRUSTED_HEADERS);
        } finally {
            if (null === $previous) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }
}
