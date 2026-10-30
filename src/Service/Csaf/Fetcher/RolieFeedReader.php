<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Fetcher;

use App\Service\Csaf\Exception\CsafFetchException;
use App\Service\Csaf\Exception\CsafParseException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a ROLIE Atom feed and returns CSAF document descriptors filtered by updated date.
 */
final readonly class RolieFeedReader
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%app.csaf.http_timeout_seconds%')]
        private int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @return list<array{url: string, updated: \DateTimeImmutable}>
     */
    public function readUpdatedSince(string $feedUrl, ?\DateTimeImmutable $since = null): array
    {
        try {
            $response = $this->httpClient->request('GET', $feedUrl, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'application/atom+xml,application/xml,application/json'],
            ]);
            $payload = $response->getContent();
            $contentType = (string) ($response->getHeaders()['content-type'][0] ?? '');
        } catch (HttpExceptionInterface $e) {
            throw new CsafFetchException('ROLIE feed fetch failed: '.$e->getMessage(), 0, $e);
        }

        if (str_contains($contentType, 'json') || str_starts_with(trim($payload), '{')) {
            return $this->parseJsonFeed($payload, $since);
        }

        return $this->parseAtomFeed($payload, $since);
    }

    /**
     * @return list<array{url: string, updated: \DateTimeImmutable}>
     */
    private function parseJsonFeed(string $payload, ?\DateTimeImmutable $since): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CsafParseException('ROLIE JSON feed malformed: '.$e->getMessage(), 0, $e);
        }

        $feed = $data['feed'] ?? $data;
        if (!\is_array($feed)) {
            return [];
        }
        $entries = $feed['entry'] ?? [];
        if (!\is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $updatedRaw = (string) ($entry['updated'] ?? '');
            $updated = $this->parseDate($updatedRaw);
            if (null !== $since && $updated < $since) {
                continue;
            }
            $url = $this->extractLink($entry);
            if (null !== $url) {
                $out[] = ['url' => $url, 'updated' => $updated];
            }
        }

        return $out;
    }

    /**
     * @return list<array{url: string, updated: \DateTimeImmutable}>
     */
    private function parseAtomFeed(string $payload, ?\DateTimeImmutable $since): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($payload);
        libxml_use_internal_errors($previous);
        if (false === $xml) {
            throw new CsafParseException('ROLIE Atom feed not parseable as XML.');
        }

        $out = [];
        foreach ($xml->entry ?? [] as $entry) {
            $updatedRaw = (string) ($entry->updated ?? '');
            $updated = $this->parseDate($updatedRaw);
            if (null !== $since && $updated < $since) {
                continue;
            }
            $url = null;
            foreach ($entry->link as $link) {
                $rel = (string) $link['rel'];
                $href = (string) $link['href'];
                if ('' === $href) {
                    continue;
                }
                if ('' === $rel || 'self' === $rel || 'enclosure' === $rel) {
                    $url = $href;
                    break;
                }
            }
            if (null !== $url) {
                $out[] = ['url' => $url, 'updated' => $updated];
            }
        }

        return $out;
    }

    private function parseDate(string $iso): \DateTimeImmutable
    {
        if ('' === $iso) {
            return new \DateTimeImmutable('@0');
        }
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return new \DateTimeImmutable('@0');
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractLink(array $entry): ?string
    {
        $links = $entry['link'] ?? null;
        if (\is_string($links) && '' !== $links) {
            return $links;
        }
        if (\is_array($links)) {
            foreach ($links as $link) {
                if (\is_string($link) && '' !== $link) {
                    return $link;
                }
                if (\is_array($link) && isset($link['href']) && \is_string($link['href']) && '' !== $link['href']) {
                    return $link['href'];
                }
            }
        }
        if (isset($entry['url']) && \is_string($entry['url']) && '' !== $entry['url']) {
            return $entry['url'];
        }

        return null;
    }
}
