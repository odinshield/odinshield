<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Http;

use App\Service\Http\OutboundHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SEC-021 (#190): https only, a byte budget per response, and — in the production stacking
 * NoPrivateNetwork(Outbound(real client)) — both rules plus the private-network block on every
 * redirect hop. Public IP literals keep the tests free of DNS (the mock never connects; the
 * TEST-NET ranges would be blocked as reserved).
 */
final class OutboundHttpClientTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    public function testPlainHttpIsRefusedBeforeAnyRequest(): void
    {
        $client = new OutboundHttpClient($this->mock(fn () => new MockResponse('ok')), 1000);

        try {
            $client->request('GET', 'http://93.184.215.14/feed.json');
            self::fail('A plain http URL must be refused.');
        } catch (TransportException $e) {
            self::assertStringContainsString('Only https', $e->getMessage());
        }
        self::assertSame([], $this->requested);
    }

    public function testBodyWithinBudgetIsDelivered(): void
    {
        $client = new OutboundHttpClient($this->mock(fn () => new MockResponse(str_repeat('a', 1000))), 1000);

        self::assertSame(1000, \strlen($client->request('GET', 'https://93.184.215.14/doc.json')->getContent()));
    }

    public function testBodyOverBudgetIsCutOff(): void
    {
        // Chunked, no Content-Length: only the streaming byte count can catch it.
        $client = new OutboundHttpClient($this->mock(fn () => new MockResponse([str_repeat('a', 600), str_repeat('a', 600)])), 1000);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('exceeds the limit of 1000 bytes');
        $client->request('GET', 'https://93.184.215.14/endless.json')->getContent();
    }

    public function testAnnouncedLengthOverBudgetIsRefused(): void
    {
        $client = new OutboundHttpClient($this->mock(fn () => new MockResponse('x', [
            'response_headers' => ['content-length' => '5000'],
        ])), 1000);

        $this->expectException(TransportException::class);
        $client->request('GET', 'https://93.184.215.14/huge.json')->getContent();
    }

    public function testCallerProgressCallbackStillRuns(): void
    {
        $client = new OutboundHttpClient($this->mock(fn () => new MockResponse('abc')), 1000);
        $seen = 0;

        $client->request('GET', 'https://93.184.215.14/doc.json', [
            'on_progress' => function (int $downloaded) use (&$seen): void {
                $seen = max($seen, $downloaded);
            },
        ])->getContent();

        self::assertSame(3, $seen);
    }

    public function testPrivateAndLoopbackTargetsAreBlocked(): void
    {
        $client = new NoPrivateNetworkHttpClient(new OutboundHttpClient($this->mock(fn () => new MockResponse('ok')), 1000));

        foreach (['https://127.0.0.1/', 'https://10.0.0.5/', 'https://172.18.0.2:5432/', 'https://169.254.169.254/latest/meta-data/', 'https://[::1]/'] as $url) {
            try {
                $client->request('GET', $url)->getContent();
                self::fail(\sprintf('"%s" must be blocked.', $url));
            } catch (TransportException $e) {
                self::assertStringContainsString('is blocked', $e->getMessage(), $url);
            }
        }
        self::assertSame([], $this->requested, 'A blocked target must never reach the transport.');
    }

    public function testRedirectToPlainHttpIsRefusedBeforeTheHopIsSent(): void
    {
        $client = new NoPrivateNetworkHttpClient(new OutboundHttpClient($this->mock(fn (string $method, string $url) => str_ends_with($url, '/start')
            ? new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://93.184.215.14/next', 'response_headers' => ['location' => 'http://93.184.215.14/next']])
            : new MockResponse('reached')), 1000));

        try {
            $client->request('GET', 'https://93.184.215.14/start')->getContent();
            self::fail('A redirect to plain http must be refused.');
        } catch (TransportException $e) {
            self::assertStringContainsString('Only https', $e->getMessage());
        }
        self::assertSame(['https://93.184.215.14/start'], $this->requested);
    }

    public function testRedirectIntoPrivateNetworkIsBlocked(): void
    {
        $client = new NoPrivateNetworkHttpClient(new OutboundHttpClient($this->mock(fn (string $method, string $url) => str_ends_with($url, '/start')
            ? new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://169.254.169.254/latest/meta-data/', 'response_headers' => ['location' => 'https://169.254.169.254/latest/meta-data/']])
            : new MockResponse('secret')), 1000));

        try {
            $client->request('GET', 'https://93.184.215.14/start')->getContent();
            self::fail('A redirect into the private network must be blocked.');
        } catch (TransportException $e) {
            self::assertStringContainsString('is blocked', $e->getMessage());
        }
        self::assertSame(['https://93.184.215.14/start'], $this->requested);
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $factory
     */
    private function mock(callable $factory): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($factory): MockResponse {
            $this->requested[] = $url;

            return $factory($method, $url, $options);
        });
    }
}
