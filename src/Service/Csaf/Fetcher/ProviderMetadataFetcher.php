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
 * Loads provider-metadata.json and returns the ROLIE feeds it exposes that we can actually read.
 */
final readonly class ProviderMetadataFetcher
{
    /**
     * TLP labels whose feeds are served to anyone. WHITE is TLP 1.0, CLEAR its TLP 2.0
     * successor — providers use both spellings, so both count as public.
     */
    private const array PUBLIC_TLP_LABELS = ['WHITE', 'CLEAR'];

    /**
     * @param list<string> $skippedDirectorySegments
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%app.csaf.http_timeout_seconds%')]
        private int $timeoutSeconds = 30,
        #[Autowire('%app.csaf.skipped_directory_segments%')]
        private array $skippedDirectorySegments = [],
    ) {
    }

    /**
     * @return list<array{url: string, tlpLabel: string|null, type: 'rolie'|'directory'}> readable
     *                                                                                    distributions; the label is carried along so a future credentialed fetch can select on it
     */
    public function fetch(string $providerMetadataUrl): array
    {
        try {
            $response = $this->httpClient->request('GET', $providerMetadataUrl, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $payload = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw new CsafFetchException('Provider metadata fetch failed: '.$e->getMessage(), 0, $e);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CsafParseException('Provider metadata JSON malformed: '.$e->getMessage(), 0, $e);
        }

        $feeds = [];
        $distributions = $data['distributions'] ?? [];
        if (!\is_array($distributions)) {
            return [];
        }
        $directories = [];
        foreach ($distributions as $dist) {
            if (!\is_array($dist)) {
                continue;
            }
            // Issue #97: a directory distribution is the equal-ranking alternative to ROLIE
            // (CSAF 2.0 §7.2.2 — requirements 11-14 *or* 15-17). Collected separately because it
            // only applies when the provider offers no readable ROLIE feed; see below.
            $directoryUrl = $dist['directory_url'] ?? null;
            if (\is_string($directoryUrl) && '' !== $directoryUrl && !$this->isSkippedDirectory($directoryUrl)) {
                $directories[$directoryUrl] ??= ['url' => $directoryUrl, 'tlpLabel' => null, 'type' => 'directory'];
            }
            $rolie = $dist['rolie'] ?? null;
            if (!\is_array($rolie)) {
                continue;
            }
            $feedList = $rolie['feeds'] ?? [];
            if (!\is_array($feedList)) {
                continue;
            }
            foreach ($feedList as $feed) {
                if (!\is_array($feed) || !isset($feed['url']) || !\is_string($feed['url']) || '' === $feed['url']) {
                    continue;
                }
                $label = $feed['tlp_label'] ?? null;
                $label = \is_string($label) && '' !== $label ? strtoupper($label) : null;
                // Issue #62: a feed behind a TLP restriction belongs to a closed user group and
                // needs credentials we do not have. Reading it anyway yields a bare 404 from the
                // BSI (not 401/403), which used to look like a dead feed and left a permanent
                // error on /import-status — noise that buries real outages. A feed WITHOUT a
                // label is still read: better one feed too many than a silently dropped one.
                if (null !== $label && !\in_array($label, self::PUBLIC_TLP_LABELS, true)) {
                    continue;
                }
                // Keyed by URL so a feed listed twice collapses; array_unique() cannot do this
                // once the entries are arrays.
                $feeds[$feed['url']] ??= ['url' => $feed['url'], 'tlpLabel' => $label, 'type' => 'rolie'];
            }
        }

        // A readable ROLIE feed wins over the directories of the same provider. §7.2.2 makes the
        // two ways alternatives, and each is a *complete* requirement set, so ROLIE is never the
        // poorer source — but it is the only one carrying tlp_label per entry.
        //
        // Measured on Q with v2.0.4 (2026-09-10): KUNBUS publishes both — four labelled ROLIE
        // feeds AND four directories white/amber/green/red with no label at all. Reading both
        // fetched the WHITE advisories twice and ran into three 403s per cycle on the restricted
        // directories, recreating exactly the permanent /import-status error that #62 removed.
        // Every other provider offers only one of the two, so this is KUNBUS-shaped but not a
        // KUNBUS special case.
        return array_values([] !== $feeds ? $feeds : $directories);
    }

    /**
     * #98: a directory a provider offers but we deliberately do not read.
     *
     * Red Hat publishes two directories — `…/advisories/` and `…/vex/`. The VEX one is the larger
     * of the two (38.082 documents in a 90-day window against 25.288) and says, for the most part,
     * that a product is *not* affected. Until `known_not_affected` is actually evaluated (#57),
     * reading it would cost a lot of transfer for information the importer cannot use. The list is
     * configuration rather than a hard-coded path so the decision can be reversed without a code
     * change once #57 lands.
     *
     * Matching is on a path segment, not a prefix, so it is independent of a provider's host and
     * versioning scheme.
     */
    private function isSkippedDirectory(string $directoryUrl): bool
    {
        foreach ($this->skippedDirectorySegments as $segment) {
            if ('' !== $segment && str_contains($directoryUrl, $segment)) {
                return true;
            }
        }

        return false;
    }
}
