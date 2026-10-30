<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Import;

use App\Import\ImportSourceHealth;
use PHPUnit\Framework\TestCase;

final class ImportSourceHealthTest extends TestCase
{
    public function testCountsWholeDaysSinceTheOldestSuccess(): void
    {
        // The real case behind issue #63: ABB last succeeded on 2026-06-29 and the outage was
        // found on 2026-09-10 — 73 days in which nobody noticed.
        $health = new ImportSourceHealth(1, new \DateTimeImmutable('2026-06-29 08:10:43'));

        self::assertSame(73, $health->daysSinceOldestSuccess(new \DateTimeImmutable('2026-09-10 12:58:00')));
    }

    public function testReturnsNullWhenTheBrokenSourceNeverSucceeded(): void
    {
        // "never worked" is a different statement from "broken since N days" — the template says
        // so in its own words rather than printing a misleading age.
        $health = new ImportSourceHealth(1, null);

        self::assertNull($health->daysSinceOldestSuccess(new \DateTimeImmutable('2026-09-10')));
    }

    public function testSameDayOutageIsZeroDaysNotNull(): void
    {
        $health = new ImportSourceHealth(2, new \DateTimeImmutable('2026-09-10 06:00:00'));

        self::assertSame(0, $health->daysSinceOldestSuccess(new \DateTimeImmutable('2026-09-10 18:00:00')));
    }
}
