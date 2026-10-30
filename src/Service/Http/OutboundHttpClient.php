<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Http;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * SEC-021 (#190): transport rules for every outbound fetch (CSAF, EPSS, KEV, recheck).
 *
 * Only https, and no response body above a fixed byte budget — counted while streaming, so an
 * oversized or never-ending answer is cut off before it is buffered into memory. Wired in
 * services.yaml *inside* NoPrivateNetworkHttpClient: that decorator follows redirects itself and
 * sends each hop through this client, so a redirect to plain http is refused before it goes out.
 * Every rejection is a TransportException, which the callers already catch as a fetch failure.
 */
final class OutboundHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly int $maxResponseBytes,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        if (!\is_string($scheme) || 'https' !== strtolower($scheme)) {
            throw new TransportException(\sprintf('Only https URLs may be fetched, refused "%s".', $url));
        }

        $maxBytes = $this->maxResponseBytes;
        $onProgress = $options['on_progress'] ?? null;
        $options['on_progress'] = static function (int $downloaded, int $announced, array $info) use ($onProgress, $maxBytes): void {
            if ($downloaded > $maxBytes || $announced > $maxBytes) {
                throw new TransportException(\sprintf('Response from "%s" exceeds the limit of %d bytes.', $info['url'] ?? '', $maxBytes));
            }

            if (\is_callable($onProgress)) {
                $onProgress($downloaded, $announced, $info);
            }
        };

        return $this->client->request($method, $url, $options);
    }
}
