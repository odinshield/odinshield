<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf;

use App\Service\Csaf\Exception\CsafFetchException;
use App\Service\Csaf\Fetcher\ChangesCsvReader;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #97: directory-based CSAF providers (Red Hat, Schneider, …) publish a changes.csv
 * instead of a ROLIE feed. The reader must produce the exact shape RolieFeedReader produces,
 * so everything downstream stays unaware of which distribution kind it is looking at.
 */
final class ChangesCsvReaderTest extends TestCase
{
    private const string DIR_URL = 'https://prov.test/.well-known/csaf/';

    public function testResolvesRelativePathsAgainstTheDirectoryUrl(): void
    {
        // The single most likely way to get this wrong: ROLIE hands out absolute URLs, changes.csv
        // hands out paths relative to the directory. Every document fetch would 404.
        $entries = $this->read("\"2026/adv-1.json\",\"2026-09-07T15:26:36+00:00\"\n");

        self::assertSame('https://prov.test/.well-known/csaf/2026/adv-1.json', $entries[0]['url']);
    }

    public function testJoinsCorrectlyWhenDirectoryUrlHasNoTrailingSlash(): void
    {
        $client = new MockHttpClient(new MockResponse("\"2026/adv-1.json\",\"2026-09-07T15:26:36Z\"\n"));
        $entries = (new ChangesCsvReader($client, 30))->readUpdatedSince('https://prov.test/csaf');

        self::assertSame('https://prov.test/csaf/2026/adv-1.json', $entries[0]['url']);
    }

    public function testLeavesAbsoluteUrlsUntouched(): void
    {
        $entries = $this->read("\"https://cdn.test/adv-9.json\",\"2026-09-07T15:26:36Z\"\n");

        self::assertSame('https://cdn.test/adv-9.json', $entries[0]['url']);
    }

    public function testParsesAllThreeTimestampDialectsSeenInTheWild(): void
    {
        $entries = $this->read(
            "\"a.json\",\"2026-09-07T15:26:36+00:00\"\n"   // Red Hat
            ."\"b.json\",\"2026-09-08T07:00:00.000Z\"\n"   // Schneider
            ."\"c.json\",\"2026-07-13T16:44:34Z\"\n"       // Stackable
        );

        self::assertCount(3, $entries);
        self::assertSame('2026-09-07T15:26:36+00:00', $entries[0]['updated']->format('c'));
        self::assertSame('2026-09-08T07:00:00+00:00', $entries[1]['updated']->format('c'));
        self::assertSame('2026-07-13T16:44:34+00:00', $entries[2]['updated']->format('c'));
    }

    public function testFiltersOutEntriesOlderThanTheWatermark(): void
    {
        $entries = $this->read(
            "\"new.json\",\"2026-09-07T00:00:00Z\"\n"
            ."\"old.json\",\"2026-01-01T00:00:00Z\"\n",
            new \DateTimeImmutable('2026-06-01T00:00:00Z'),
        );

        self::assertSame(['https://prov.test/.well-known/csaf/new.json'], array_column($entries, 'url'));
    }

    public function testSkipsRowsWhoseTimestampCannotBeParsed(): void
    {
        // Importing an undatable row on every run, or never, are both wrong — and which one you
        // get would depend on an arbitrary fallback date. Dropping it is the honest choice.
        $entries = $this->read(
            "\"broken.json\",\"not-a-date\"\n"
            ."\"empty.json\",\"\"\n"
            ."\"fine.json\",\"2026-09-07T00:00:00Z\"\n"
        );

        self::assertSame(['https://prov.test/.well-known/csaf/fine.json'], array_column($entries, 'url'));
    }

    public function testIgnoresBlankAndMalformedLines(): void
    {
        $entries = $this->read(
            "\n"
            ."\"only-one-column.json\"\n"
            ."\"\",\"2026-09-07T00:00:00Z\"\n"
            ."\"fine.json\",\"2026-09-07T00:00:00Z\"\n"
            ."\n"
        );

        self::assertCount(1, $entries);
    }

    public function testHandlesUnquotedRowsAndWindowsLineEndings(): void
    {
        $entries = $this->read("2026/adv-1.json,2026-09-07T15:26:36Z\r\n2026/adv-2.json,2026-09-08T15:26:36Z\r\n");

        self::assertCount(2, $entries);
        self::assertSame('https://prov.test/.well-known/csaf/2026/adv-1.json', $entries[0]['url']);
    }

    public function testRequestsChangesCsvInsideTheDirectory(): void
    {
        $requested = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested = $url;

            return new MockResponse('');
        });
        (new ChangesCsvReader($client, 30))->readUpdatedSince(self::DIR_URL);

        self::assertSame(self::DIR_URL.'changes.csv', $requested);
    }

    public function testFetchFailureBecomesACsafFetchException(): void
    {
        // The runner catches this per feed and keeps the other distributions going; an
        // unwrapped transport exception would abort the whole provider instead.
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(CsafFetchException::class);
        (new ChangesCsvReader($client, 30))->readUpdatedSince(self::DIR_URL);
    }

    /**
     * A directory we may not read is a closed user group, not a provider outage. Treating it as a
     * failure would leave a permanent /import-status error nobody can act on — the exact problem
     * #62 fixed for labelled ROLIE feeds. A directory carries no tlp_label, so the status code is
     * the only signal available.
     */
    #[TestWith([401])]
    #[TestWith([403])]
    public function testAccessDeniedYieldsNothingInsteadOfAnError(int $status): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => $status]));

        self::assertSame([], (new ChangesCsvReader($client, 30))->readUpdatedSince(self::DIR_URL));
    }

    /**
     * @return list<array{url: string, updated: \DateTimeImmutable}>
     */
    private function read(string $csv, ?\DateTimeImmutable $since = null): array
    {
        return (new ChangesCsvReader(new MockHttpClient(new MockResponse($csv)), 30))
            ->readUpdatedSince(self::DIR_URL, $since);
    }
}
