<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf;

use App\Service\Csaf\Exception\CsafParseException;
use App\Service\Csaf\Fetcher\ProviderMetadataFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #62: feeds behind a TLP restriction must never be requested. The BSI answers
 * unauthenticated requests for its TLP:GREEN feeds with a bare 404, which looked like a dead
 * feed and left a permanent error on /import-status — noise that buries real outages.
 */
final class ProviderMetadataFetcherTest extends TestCase
{
    public function testKeepsOnlyPubliclyReadableFeeds(): void
    {
        $feeds = $this->fetchWith([
            ['url' => 'https://prov.test/green/wid-green.json', 'tlp_label' => 'GREEN'],
            ['url' => 'https://prov.test/white/wid-white.json', 'tlp_label' => 'WHITE'],
            ['url' => 'https://prov.test/amber/wid-amber.json', 'tlp_label' => 'AMBER'],
            ['url' => 'https://prov.test/red/wid-red.json', 'tlp_label' => 'RED'],
            ['url' => 'https://prov.test/clear/wid-clear.json', 'tlp_label' => 'CLEAR'],
        ]);

        self::assertSame(
            ['https://prov.test/white/wid-white.json', 'https://prov.test/clear/wid-clear.json'],
            array_column($feeds, 'url'),
            'Only WHITE (TLP 1.0) and CLEAR (TLP 2.0) feeds are served to unauthenticated clients.',
        );
    }

    public function testKeepsFeedWithoutAnyLabel(): void
    {
        $feeds = $this->fetchWith([
            ['url' => 'https://prov.test/unlabelled.json'],
            ['url' => 'https://prov.test/green.json', 'tlp_label' => 'GREEN'],
        ]);

        // Defensive on purpose: a provider that omits the label may still serve the feed
        // publicly. Better one feed too many than a silently dropped source.
        self::assertSame(['https://prov.test/unlabelled.json'], array_column($feeds, 'url'));
        self::assertNull($feeds[0]['tlpLabel']);
    }

    public function testCarriesTheLabelAlongForLaterCredentialedFetching(): void
    {
        $feeds = $this->fetchWith([
            ['url' => 'https://prov.test/white.json', 'tlp_label' => 'WHITE'],
        ]);

        self::assertSame([['url' => 'https://prov.test/white.json', 'tlpLabel' => 'WHITE', 'type' => 'rolie']], $feeds);
    }

    public function testLabelComparisonIsCaseInsensitive(): void
    {
        $feeds = $this->fetchWith([
            ['url' => 'https://prov.test/lower-white.json', 'tlp_label' => 'white'],
            ['url' => 'https://prov.test/lower-green.json', 'tlp_label' => 'green'],
        ]);

        self::assertSame(['https://prov.test/lower-white.json'], array_column($feeds, 'url'));
        self::assertSame('WHITE', $feeds[0]['tlpLabel'], 'The label is normalised to upper case.');
    }

    public function testProviderWithOnlyRestrictedFeedsYieldsNothing(): void
    {
        // The runner turns an empty list into a provider failure. That is intended: a provider we
        // cannot read anything from is not a healthy provider — but the message it produces must
        // say "no readable feeds", not "no feeds", or it describes the wrong problem.
        self::assertSame([], $this->fetchWith([
            ['url' => 'https://prov.test/green.json', 'tlp_label' => 'GREEN'],
            ['url' => 'https://prov.test/red.json', 'tlp_label' => 'RED'],
        ]));
    }

    public function testDeduplicatesRepeatedFeedUrls(): void
    {
        $feeds = $this->fetchWith([
            ['url' => 'https://prov.test/white.json', 'tlp_label' => 'WHITE'],
            ['url' => 'https://prov.test/white.json', 'tlp_label' => 'WHITE'],
        ]);

        self::assertCount(1, $feeds);
    }

    public function testIgnoresEntriesWithoutUsableUrl(): void
    {
        $feeds = $this->fetchWith([
            ['url' => '', 'tlp_label' => 'WHITE'],
            ['tlp_label' => 'WHITE'],
            ['url' => 42, 'tlp_label' => 'WHITE'],
            ['url' => 'https://prov.test/ok.json', 'tlp_label' => 'WHITE'],
        ]);

        self::assertSame(['https://prov.test/ok.json'], array_column($feeds, 'url'));
    }

    public function testDirectoryDistributionIsReturned(): void
    {
        // Issue #97: CSAF 2.0 §7.2.2 makes the directory an equal-ranking alternative to ROLIE.
        // Before this, every such provider yielded an empty list and was booked as a failure.
        $feeds = $this->fetchDistributions([['directory_url' => 'https://prov.test/advisories/']]);

        self::assertSame(
            [['url' => 'https://prov.test/advisories/', 'tlpLabel' => null, 'type' => 'directory']],
            $feeds,
        );
    }

    public function testRolieWinsWhenAProviderOffersBothKinds(): void
    {
        // KUNBUS-shaped, measured on Q with v2.0.4: four labelled ROLIE feeds AND four unlabelled
        // directories white/amber/green/red. Reading both fetched WHITE twice and hit three 403s
        // per cycle — the very /import-status noise #62 removed. §7.2.2 makes each way a complete
        // requirement set, so ROLIE is never poorer, and it is the only one carrying tlp_label.
        $feeds = $this->fetchDistributions([
            ['rolie' => ['feeds' => [
                ['url' => 'https://prov.test/white/feed.json', 'tlp_label' => 'WHITE'],
                ['url' => 'https://prov.test/amber/feed.json', 'tlp_label' => 'AMBER'],
            ]]],
            ['directory_url' => 'https://prov.test/white'],
            ['directory_url' => 'https://prov.test/amber'],
        ]);

        self::assertSame(
            [['url' => 'https://prov.test/white/feed.json', 'tlpLabel' => 'WHITE', 'type' => 'rolie']],
            $feeds,
            'Only the readable ROLIE feed survives; the directories are not read alongside it.',
        );
    }

    public function testDirectoriesAreUsedWhenEveryRolieFeedIsRestricted(): void
    {
        // The fallback is on *readable* feeds, not on their mere presence: a provider whose ROLIE
        // side is entirely TLP-restricted still has a usable directory, and dropping both would
        // silence the source completely.
        $feeds = $this->fetchDistributions([
            ['rolie' => ['feeds' => [['url' => 'https://prov.test/green/feed.json', 'tlp_label' => 'GREEN']]]],
            ['directory_url' => 'https://prov.test/advisories/'],
        ]);

        self::assertSame(['directory'], array_column($feeds, 'type'));
    }

    public function testMultipleDirectoriesOfOneProviderAreAllReturned(): void
    {
        // Red Hat splits advisories and VEX into two directories; Open-Xchange uses two products.
        $feeds = $this->fetchDistributions([
            ['directory_url' => 'https://prov.test/advisories/'],
            ['directory_url' => 'https://prov.test/vex/'],
        ]);

        self::assertCount(2, $feeds);
    }

    public function testDistributionWithNeitherKindIsSkipped(): void
    {
        self::assertSame([], $this->fetchDistributions([['something_else' => 'x'], ['directory_url' => '']]));
    }

    public function testMalformedJsonThrows(): void
    {
        $client = new MockHttpClient(new MockResponse('{not json'));

        $this->expectException(CsafParseException::class);
        (new ProviderMetadataFetcher($client, 30))->fetch('https://prov.test/pm.json');
    }

    /**
     * @param list<array<string, mixed>> $feedEntries
     *
     * @return list<array{url: string, tlpLabel: string|null, type: 'rolie'|'directory'}>
     */
    private function fetchWith(array $feedEntries): array
    {
        return $this->fetchDistributions([['rolie' => ['feeds' => $feedEntries]]]);
    }

    /**
     * #98: Red Hat offers two directories, `…/advisories/` and `…/vex/`. The VEX one is bigger
     * than the advisory one and states mostly that a product is NOT affected — until #57 can act
     * on that, reading it is transfer without benefit. Configured, not hard-coded, so the decision
     * can be reversed without touching code.
     */
    public function testConfiguredDirectorySegmentIsSkipped(): void
    {
        $feeds = $this->fetchDistributions([
            ['directory_url' => 'https://prov.test/data/csaf/v2/advisories/'],
            ['directory_url' => 'https://prov.test/data/csaf/v2/vex/'],
        ], ['/csaf/v2/vex/']);

        self::assertSame(
            ['https://prov.test/data/csaf/v2/advisories/'],
            array_column($feeds, 'url'),
        );
    }

    public function testWithoutConfigurationBothDirectoriesAreReturned(): void
    {
        $feeds = $this->fetchDistributions([
            ['directory_url' => 'https://prov.test/data/csaf/v2/advisories/'],
            ['directory_url' => 'https://prov.test/data/csaf/v2/vex/'],
        ]);

        self::assertCount(2, $feeds, 'The skip list is opt-in; an empty one must change nothing.');
    }

    /**
     * The skip list must not reach ROLIE feeds — it is about directories a provider offers in
     * addition, not about filtering a feed whose URL happens to contain the segment.
     */
    public function testSkipListDoesNotTouchRolieFeeds(): void
    {
        $feeds = $this->fetchDistributions([
            ['rolie' => ['feeds' => [['url' => 'https://prov.test/csaf/v2/vex/feed.json', 'tlp_label' => 'WHITE']]]],
        ], ['/csaf/v2/vex/']);

        self::assertCount(1, $feeds);
        self::assertSame('rolie', $feeds[0]['type']);
    }

    /**
     * @param list<array<string, mixed>> $distributions
     * @param list<string>               $skippedSegments
     *
     * @return list<array{url: string, tlpLabel: string|null, type: 'rolie'|'directory'}>
     */
    private function fetchDistributions(array $distributions, array $skippedSegments = []): array
    {
        $client = new MockHttpClient(new MockResponse(json_encode(
            ['distributions' => $distributions],
            \JSON_THROW_ON_ERROR,
        )));

        return (new ProviderMetadataFetcher($client, 30, $skippedSegments))->fetch('https://prov.test/pm.json');
    }
}
