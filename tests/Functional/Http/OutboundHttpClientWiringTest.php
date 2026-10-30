<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Http;

use App\Service\Http\OutboundHttpClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SEC-021 (#190): the autowired HttpClientInterface — what every fetcher receives — carries both
 * guards, in the order that makes redirect hops pass through OutboundHttpClient. Swapping the two
 * decoration priorities would let a redirect to plain http through unchecked; the unit test
 * covers the behaviour, this test pins the wiring. No request leaves the container: every URL is
 * refused before a connection is opened.
 */
final class OutboundHttpClientWiringTest extends KernelTestCase
{
    public function testNoPrivateNetworkWrapsOutboundClient(): void
    {
        $chain = array_map(get_debug_type(...), $this->chain());

        $noPrivate = array_search(NoPrivateNetworkHttpClient::class, $chain, true);
        $outbound = array_search(OutboundHttpClient::class, $chain, true);

        self::assertIsInt($noPrivate, 'NoPrivateNetworkHttpClient missing: '.implode(' -> ', $chain));
        self::assertIsInt($outbound, 'OutboundHttpClient missing: '.implode(' -> ', $chain));
        self::assertLessThan($outbound, $noPrivate, 'NoPrivateNetworkHttpClient must be outside OutboundHttpClient: '.implode(' -> ', $chain));
    }

    public function testContainerClientRefusesLoopbackAndPlainHttp(): void
    {
        $client = $this->client();

        foreach (['https://127.0.0.1/' => 'is blocked', 'http://93.184.215.14/' => 'Only https'] as $url => $reason) {
            try {
                $client->request('GET', $url)->getContent();
                self::fail(\sprintf('"%s" must be refused.', $url));
            } catch (TransportException $e) {
                self::assertStringContainsString($reason, $e->getMessage(), $url);
            }
        }
    }

    /**
     * OUTBOUND_ALLOWED_SUBNETS reaches the private-network block: an allowed loopback target is no
     * longer refused by the guard — the request goes out and fails only at the closed port.
     */
    public function testAllowedSubnetsLetTheTargetThrough(): void
    {
        $_SERVER['OUTBOUND_ALLOWED_SUBNETS'] = $_ENV['OUTBOUND_ALLOWED_SUBNETS'] = '10.99.0.0/16,127.0.0.1/32';
        try {
            $client = $this->client();
            try {
                $client->request('GET', 'https://127.0.0.1:9/', ['timeout' => 2])->getContent();
                self::fail('Nothing listens on port 9; the request must fail at the transport.');
            } catch (TransportException $e) {
                self::assertStringNotContainsString('is blocked', $e->getMessage());
            }
        } finally {
            unset($_SERVER['OUTBOUND_ALLOWED_SUBNETS'], $_ENV['OUTBOUND_ALLOWED_SUBNETS']);
            self::ensureKernelShutdown();
        }
    }

    private function client(): HttpClientInterface
    {
        self::bootKernel();
        $client = self::getContainer()->get(HttpClientInterface::class);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        return $client;
    }

    /**
     * @return list<object> outermost first, following each decorator's private `client` property
     */
    private function chain(): array
    {
        $chain = [];
        $current = $this->client();
        while (null !== $current && \count($chain) < 20) {
            $chain[] = $current;
            $next = null;
            for ($class = new \ReflectionObject($current); false !== $class; $class = $class->getParentClass()) {
                if ($class->hasProperty('client')) {
                    $value = $class->getProperty('client')->getValue($current);
                    $next = $value instanceof HttpClientInterface ? $value : null;
                    break;
                }
            }
            $current = $next;
        }

        return $chain;
    }
}
