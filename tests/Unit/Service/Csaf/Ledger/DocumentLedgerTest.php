<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Ledger;

use App\Enum\CsafDocumentOutcome;
use App\Service\Csaf\Ledger\DocumentLedger;
use PHPUnit\Framework\TestCase;

/**
 * Issue #319: the pending-set computation and the rebuild reference of the document ledger.
 */
final class DocumentLedgerTest extends TestCase
{
    public function testAnEntryWithoutRecordIsPending(): void
    {
        $result = DocumentLedger::pending([self::entry('a', '2026-10-20T22:00:00Z')], [], 5);

        self::assertSame(['a'], self::urls($result['pending']));
    }

    public function testAHandledEntryAtTheSameStampIsNotPending(): void
    {
        $handled = ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Processed)];

        $result = DocumentLedger::pending([self::entry('a', '2026-10-20T22:00:00Z')], $handled, 5);

        self::assertSame([], $result['pending']);
        self::assertSame([], $result['givenUp']);
    }

    public function testANewerStampMakesAHandledEntryPendingAgain(): void
    {
        $handled = ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Rebuild)];

        $result = DocumentLedger::pending([self::entry('a', '2026-10-21T22:00:00Z')], $handled, 5);

        self::assertSame(['a'], self::urls($result['pending']));
    }

    public function testAnOlderStampThanTheRecordIsNotPending(): void
    {
        // A provider moving a stamp backwards does not make us re-read the document.
        $handled = ['a' => self::row('2026-10-21T22:00:00Z', CsafDocumentOutcome::Processed)];

        $result = DocumentLedger::pending([self::entry('a', '2026-10-20T22:00:00Z')], $handled, 5);

        self::assertSame([], $result['pending']);
    }

    public function testAFailedEntryIsPendingUntilTheAttemptLimit(): void
    {
        $entries = [self::entry('a', '2026-10-20T22:00:00Z')];

        $retry = DocumentLedger::pending($entries, ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Failed, attempts: 4)], 5);
        $givenUp = DocumentLedger::pending($entries, ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Failed, attempts: 5)], 5);

        self::assertSame(['a'], self::urls($retry['pending']));
        self::assertSame([], $givenUp['pending']);
        self::assertSame(['a'], self::urls($givenUp['givenUp']));
    }

    public function testAGivenUpEntryWithANewerStampIsPendingAgain(): void
    {
        $handled = ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Failed, attempts: 5)];

        $result = DocumentLedger::pending([self::entry('a', '2026-10-22T22:00:00Z')], $handled, 5);

        self::assertSame(['a'], self::urls($result['pending']));
        self::assertSame([], $result['givenUp']);
    }

    public function testPendingIsSortedOldestFirstWithTiesByUrl(): void
    {
        // Newest first, as Red Hat's changes.csv lists it (#169); same-stamp group as BSI has it.
        $entries = [
            self::entry('c', '2026-10-22T10:00:00Z'),
            self::entry('b', '2026-10-20T22:00:00Z'),
            self::entry('a', '2026-10-20T22:00:00Z'),
        ];

        self::assertSame(['a', 'b', 'c'], self::urls(DocumentLedger::pending($entries, [], 5)['pending']));
    }

    public function testAUrlListedByTwoFeedsCountsOnceWithItsNewestStamp(): void
    {
        $entries = [
            self::entry('a', '2026-10-20T22:00:00Z'),
            self::entry('a', '2026-10-21T22:00:00Z'),
        ];

        $pending = DocumentLedger::pending($entries, [], 5)['pending'];

        self::assertCount(1, $pending);
        self::assertEquals(new \DateTimeImmutable('2026-10-21T22:00:00Z'), $pending[0]['updated']);
    }

    public function testStampsWithDifferentOffsetsAreComparedAsInstants(): void
    {
        // The record comes back from the database in UTC, a feed may carry an offset.
        $handled = ['a' => self::row('2026-10-20T22:00:00Z', CsafDocumentOutcome::Processed)];

        $result = DocumentLedger::pending([self::entry('a', '2026-10-21T00:00:00+02:00')], $handled, 5);

        self::assertSame([], $result['pending']);
    }

    public function testSeenContentOfAHandledRecordIsItsContentChange(): void
    {
        $record = self::row('2026-10-21T09:00:00Z', CsafDocumentOutcome::Processed, contentChangedAt: '2026-10-20T08:00:00Z');

        self::assertEquals(new \DateTimeImmutable('2026-10-20T08:00:00Z'), DocumentLedger::seenContentUpTo($record, null));
    }

    public function testSeenContentOfABootstrapRecordIsItsFeedStamp(): void
    {
        $record = self::row('2026-10-21T09:00:00Z', CsafDocumentOutcome::Processed);

        self::assertEquals(new \DateTimeImmutable('2026-10-21T09:00:00Z'), DocumentLedger::seenContentUpTo($record, null));
    }

    public function testAFailedRecordOnlyVouchesForAnEarlierSuccess(): void
    {
        // The failed attempt read nothing — its feed stamp must not mark content as seen.
        $failedOnly = self::row('2026-10-21T09:00:00Z', CsafDocumentOutcome::Failed, attempts: 1);
        $failedAfterSuccess = self::row('2026-10-21T09:00:00Z', CsafDocumentOutcome::Failed, attempts: 1, contentChangedAt: '2026-10-19T08:00:00Z');

        self::assertNull(DocumentLedger::seenContentUpTo($failedOnly, null));
        self::assertEquals(new \DateTimeImmutable('2026-10-19T08:00:00Z'), DocumentLedger::seenContentUpTo($failedAfterSuccess, null));
    }

    public function testTheFloorAppliesWithAndWithoutRecord(): void
    {
        $floor = new \DateTimeImmutable('2026-10-01T00:00:00Z');
        $older = self::row('2026-09-21T09:00:00Z', CsafDocumentOutcome::Processed, contentChangedAt: '2026-09-20T08:00:00Z');
        $newer = self::row('2026-10-21T09:00:00Z', CsafDocumentOutcome::Processed, contentChangedAt: '2026-10-20T08:00:00Z');

        self::assertEquals($floor, DocumentLedger::seenContentUpTo(null, $floor));
        self::assertEquals($floor, DocumentLedger::seenContentUpTo($older, $floor));
        self::assertEquals(new \DateTimeImmutable('2026-10-20T08:00:00Z'), DocumentLedger::seenContentUpTo($newer, $floor));
        self::assertNull(DocumentLedger::seenContentUpTo(null, null));
    }

    /**
     * @return array{url: string, updated: \DateTimeImmutable}
     */
    private static function entry(string $url, string $updated): array
    {
        return ['url' => $url, 'updated' => new \DateTimeImmutable($updated)];
    }

    /**
     * @return array{feedUpdated: \DateTimeImmutable, outcome: CsafDocumentOutcome, attempts: int, contentChangedAt: ?\DateTimeImmutable}
     */
    private static function row(string $feedUpdated, CsafDocumentOutcome $outcome, int $attempts = 0, ?string $contentChangedAt = null): array
    {
        return [
            'feedUpdated' => new \DateTimeImmutable($feedUpdated),
            'outcome' => $outcome,
            'attempts' => $attempts,
            'contentChangedAt' => null === $contentChangedAt ? null : new \DateTimeImmutable($contentChangedAt),
        ];
    }

    /**
     * @param list<array{url: string, updated: \DateTimeImmutable}> $entries
     *
     * @return list<string>
     */
    private static function urls(array $entries): array
    {
        return array_column($entries, 'url');
    }
}
