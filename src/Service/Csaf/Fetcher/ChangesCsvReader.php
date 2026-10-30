<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Fetcher;

use App\Service\Csaf\Exception\CsafFetchException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a directory-based CSAF distribution's `changes.csv` and returns document descriptors
 * filtered by updated date — the same shape {@see RolieFeedReader} produces for ROLIE feeds.
 *
 * CSAF 2.0 (§7.2.2) lets a provider satisfy *either* the directory requirements (11–14) *or* the
 * ROLIE ones (15–17); the two are equivalent alternatives, not a preference. Issue #97.
 */
final readonly class ChangesCsvReader
{
    /**
     * Required file name inside a directory distribution (CSAF 2.0 requirement 13). Lists every
     * document with its `updated` timestamp, newest first.
     */
    private const string CHANGES_FILE = 'changes.csv';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%app.csaf.http_timeout_seconds%')]
        private int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @return list<array{url: string, updated: \DateTimeImmutable}>
     */
    public function readUpdatedSince(string $directoryUrl, ?\DateTimeImmutable $since = null): array
    {
        $changesUrl = $this->joinUrl($directoryUrl, self::CHANGES_FILE);

        try {
            $response = $this->httpClient->request('GET', $changesUrl, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'text/csv,text/plain'],
            ]);
            // A directory we are not allowed to read is not a provider outage — it is a closed
            // user group we have no credentials for, the same situation #62 handles for labelled
            // ROLIE feeds. Reporting it as a feed failure would put a permanent error on
            // /import-status that no operator can act on. Unlike a ROLIE feed, a directory
            // carries no tlp_label to filter on beforehand, so the status code is all we have.
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                return [];
            }
            // Read in full rather than streaming: the largest observed changes.csv (Red Hat's VEX
            // directory) is ~3.7 MB, an order of magnitude below the ~38 MB ROLIE feed this
            // project already decodes. If the volume work in #98 needs it, this is the place to
            // switch to a line-wise stream — the CSV allows it, the JSON feed does not.
            $payload = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw new CsafFetchException('changes.csv fetch failed: '.$e->getMessage(), 0, $e);
        }

        $out = [];
        foreach (explode("\n", $payload) as $line) {
            $line = trim($line, "\r\n");
            if ('' === $line) {
                continue;
            }
            $row = str_getcsv($line, ',', '"', '\\');
            if (!isset($row[0], $row[1]) || !\is_string($row[0]) || !\is_string($row[1])) {
                continue;
            }
            $path = trim($row[0]);
            if ('' === $path) {
                continue;
            }
            $updated = $this->parseDate(trim($row[1]));
            if (null === $updated) {
                // A row we cannot date is worse than useless: it would either be imported on every
                // single run or skipped forever, depending on which way we guessed. Drop it.
                continue;
            }
            if (null !== $since && $updated < $since) {
                continue;
            }
            $out[] = ['url' => $this->joinUrl($directoryUrl, $path), 'updated' => $updated];
        }

        return $out;
    }

    /**
     * Paths in changes.csv are relative to the directory URL ("2026/rhsa-2026_4498.json"), while
     * ROLIE entries carry absolute URLs. Getting this wrong sends every document fetch of a
     * directory provider into a 404, so an already-absolute path is passed through untouched.
     */
    private function joinUrl(string $directoryUrl, string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($directoryUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * Providers spell the timestamp in at least three ways, all valid ISO 8601 and all seen in the
     * wild on 2026-09-10: "2026-09-07T15:26:36+00:00" (Red Hat), "2026-09-08T07:00:00.000Z"
     * (Schneider) and "2026-07-13T16:44:34Z" (Stackable).
     */
    private function parseDate(string $iso): ?\DateTimeImmutable
    {
        if ('' === $iso) {
            return null;
        }

        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }
    }
}
