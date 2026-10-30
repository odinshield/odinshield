<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Entity;

use App\Entity\CsafDocumentRecord;
use App\Enum\CsafDocumentOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Issue #319: attempt counting and stamp handling of one ledger record.
 */
final class CsafDocumentRecordTest extends TestCase
{
    private const string URL = 'https://example.test/csaf/2026/doc-1.json';

    public function testAFirstFailureCountsOneAttempt(): void
    {
        $record = CsafDocumentRecord::failed('prov', self::URL, new \DateTimeImmutable('2026-10-20T10:00:00Z'), new \DateTimeImmutable('2026-10-20T12:00:00Z'), 'HTTP 503');

        self::assertSame(CsafDocumentOutcome::Failed, $record->getOutcome());
        self::assertSame(1, $record->getAttempts());
        self::assertSame('HTTP 503', $record->getLastError());
    }

    public function testRepeatedFailuresOfTheSameVersionAreCountedUntilGivenUp(): void
    {
        $stamp = new \DateTimeImmutable('2026-10-20T10:00:00Z');
        $record = CsafDocumentRecord::failed('prov', self::URL, $stamp, new \DateTimeImmutable('2026-10-20T12:00:00Z'), 'HTTP 503');

        for ($run = 2; $run <= 5; ++$run) {
            self::assertFalse($record->isGivenUp(5), \sprintf('Not given up before attempt %d.', $run));
            $record->recordFailure($stamp, new \DateTimeImmutable(\sprintf('2026-10-2%dT12:00:00Z', $run)), 'HTTP 503');
        }

        self::assertSame(5, $record->getAttempts());
        self::assertTrue($record->isGivenUp(5));
    }

    public function testARepublishedDocumentStartsCountingAgain(): void
    {
        // The provider fixed a broken document and republished it: the new version must get its
        // own attempts instead of inheriting the give-up of its predecessor.
        $record = CsafDocumentRecord::failed('prov', self::URL, new \DateTimeImmutable('2026-10-20T10:00:00Z'), new \DateTimeImmutable('2026-10-20T12:00:00Z'), 'parse error');
        $record->recordFailure(new \DateTimeImmutable('2026-10-20T10:00:00Z'), new \DateTimeImmutable('2026-10-21T12:00:00Z'), 'parse error');

        $record->recordFailure(new \DateTimeImmutable('2026-10-22T09:00:00Z'), new \DateTimeImmutable('2026-10-22T12:00:00Z'), 'HTTP 503');

        self::assertSame(1, $record->getAttempts());
    }

    public function testASuccessResetsAttemptsAndError(): void
    {
        $stamp = new \DateTimeImmutable('2026-10-20T10:00:00Z');
        $record = CsafDocumentRecord::failed('prov', self::URL, $stamp, new \DateTimeImmutable('2026-10-20T12:00:00Z'), 'HTTP 503');

        $record->recordHandled($stamp, new \DateTimeImmutable('2026-10-21T12:00:00Z'), CsafDocumentOutcome::Processed, 'WID-SEC-W-2026-4133', '2');

        self::assertSame(CsafDocumentOutcome::Processed, $record->getOutcome());
        self::assertSame(0, $record->getAttempts());
        self::assertNull($record->getLastError());
        self::assertFalse($record->isGivenUp(5));
        self::assertSame('WID-SEC-W-2026-4133', $record->getDocId());
    }

    public function testAFailureKeepsTheContentChangeOfTheLastSuccess(): void
    {
        // The rebuild check of the next successful download compares against this stamp; a
        // failure in between must not erase it.
        $record = CsafDocumentRecord::handled('prov', self::URL, new \DateTimeImmutable('2026-10-20T10:00:00Z'), new \DateTimeImmutable('2026-10-20T12:00:00Z'), CsafDocumentOutcome::Processed, 'RHSA-2026:1', '1', new \DateTimeImmutable('2026-10-20T09:00:00Z'));

        $record->recordFailure(new \DateTimeImmutable('2026-10-21T10:00:00Z'), new \DateTimeImmutable('2026-10-21T12:00:00Z'), 'HTTP 503');

        self::assertEquals(new \DateTimeImmutable('2026-10-20T09:00:00Z'), $record->getContentChangedAt());
    }

    public function testABootstrapRecordHasNoDocumentDetails(): void
    {
        $record = CsafDocumentRecord::handled('prov', self::URL, new \DateTimeImmutable('2026-08-01T22:00:00Z'), new \DateTimeImmutable('2026-10-30T03:00:00Z'), CsafDocumentOutcome::Processed);

        self::assertNull($record->getDocId());
        self::assertNull($record->getContentChangedAt());
        self::assertSame(0, $record->getAttempts());
    }

    public function testStampsAreStoredInUtc(): void
    {
        // The columns have no time zone; an offset kept on the object would be lost on the way
        // into the database and shift the stamp by hours.
        $record = CsafDocumentRecord::handled('prov', self::URL, new \DateTimeImmutable('2026-10-21T00:00:00+02:00'), new \DateTimeImmutable('2026-10-21T05:00:00+02:00'), CsafDocumentOutcome::Processed, contentChangedAt: new \DateTimeImmutable('2026-10-20T23:30:00+02:00'));

        self::assertSame('2026-10-20 22:00:00 UTC', $record->getFeedUpdated()->format('Y-m-d H:i:s T'));
        self::assertSame('2026-10-21 03:00:00 UTC', $record->getHandledAt()->format('Y-m-d H:i:s T'));
        self::assertSame('2026-10-20 21:30:00 UTC', $record->getContentChangedAt()?->format('Y-m-d H:i:s T'));
    }

    public function testFailedIsNotAValidHandledOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsafDocumentRecord::handled('prov', self::URL, new \DateTimeImmutable(), new \DateTimeImmutable(), CsafDocumentOutcome::Failed);
    }
}
