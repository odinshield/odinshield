<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Matching;

use App\Service\Csaf\Matching\ComparableVersion;
use App\Service\Csaf\Matching\VersionRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionRangeTest extends TestCase
{
    /**
     * Real strings as the providers publish them (measured 2026-09-11), plus the inventory
     * versions they must and must not meet.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function intersections(): iterable
    {
        // BSI vers-like, WID-SEC-W-2026-1492 — the case that motivated the redesign (F1).
        yield 'BSI <7.6.4 meets 7.6 (major/minor inventory, D1)' => ['<7.6.4', '7.6', true];
        yield 'BSI <7.6.4 meets 7.6.3' => ['<7.6.4', '7.6.3', true];
        yield 'BSI <7.6.4 does not meet 7.6.5' => ['<7.6.4', '7.6.5', false];
        yield 'BSI <7.6.4 does not meet 7.6.4 itself' => ['<7.6.4', '7.6.4', false];
        yield 'BSI <7.4.9 does not meet 7.6' => ['<7.4.9', '7.6', false];
        yield 'BSI <7.4.9 meets 7 (major-only inventory)' => ['<7.4.9', '7', true];
        yield 'BSI <=7.6.4 meets 7.6.4' => ['<=7.6.4', '7.6.4', true];
        // BSI with a leading component token (GStreamer).
        yield 'BSI "core <1.28.3" meets 1.28' => ['core <1.28.3', '1.28', true];
        yield 'BSI "core <1.28.3" does not meet 1.28.3' => ['core <1.28.3', '1.28.3', false];
        // SICK sca-2026-0013.
        yield 'SICK <=2026.1.0 meets 2026.1.0' => ['<=2026.1.0', '2026.1.0', true];
        yield 'SICK <=2026.1.0 meets 2026' => ['<=2026.1.0', '2026', true];
        yield 'SICK <=2026.1.0 does not meet 2026.1.1' => ['<=2026.1.0', '2026.1.1', false];
        // Siemens canonical vers, SSA-019113.
        yield 'Siemens vers:intdot/>=3.1.6|<3.1.7 meets 3.1.6' => ['vers:intdot/>=3.1.6|<3.1.7', '3.1.6', true];
        yield 'Siemens vers:intdot/>=3.1.6|<3.1.7 meets 3.1 (prefix)' => ['vers:intdot/>=3.1.6|<3.1.7', '3.1', true];
        yield 'Siemens vers:intdot/>=3.1.6|<3.1.7 does not meet 3.1.5' => ['vers:intdot/>=3.1.6|<3.1.7', '3.1.5', false];
        yield 'Siemens vers:intdot/>=3.1.6|<3.1.7 does not meet 3.1.7' => ['vers:intdot/>=3.1.6|<3.1.7', '3.1.7', false];
        yield 'Siemens vers:intdot/>=3.1.6 (open) meets 4.0' => ['vers:intdot/>=3.1.6', '4.0', true];
        yield 'Siemens vers:intdot/>=3.1.6 (open) does not meet 2.9' => ['vers:intdot/>=3.1.6', '2.9', false];
        // Siemens-style prose ranges.
        yield 'Siemens "All versions < V3.0.29" meets 3.0.28' => ['All versions < V3.0.29', '3.0.28', true];
        yield 'Siemens "All versions < V3.0.29" does not meet 3.0.29' => ['All versions < V3.0.29', '3.0.29', false];
        yield 'Siemens ">= V3.1.6 < V3.1.7" with spaces meets 3.1.6' => ['>= V3.1.6 < V3.1.7', '3.1.6', true];
        // Disjoint vers intervals.
        yield 'disjoint vers meets the second interval' => ['vers:intdot/>=1.0|<1.5|>=2.0|<2.5', '2.2', true];
        yield 'disjoint vers misses the gap' => ['vers:intdot/>=1.0|<1.5|>=2.0|<2.5', '1.7', false];
        // Single versions (product_version leaves).
        yield 'single 7.6.3 meets inventory 7.6 (D1)' => ['7.6.3', '7.6', true];
        yield 'single 7.6.3 meets inventory 7.6.3' => ['7.6.3', '7.6.3', true];
        yield 'single 7.6.3 does not meet 7.6.4' => ['7.6.3', '7.6.4', false];
        yield 'single "V2.9" meets 2.9.1' => ['V2.9', '2.9.1', true];
        yield 'single with word prefix "core 1.28.3"' => ['core 1.28.3', '1.28', true];
        yield 'model number is not a version' => ['SIMATIC S7-1500 CPU 1518 V2.9', '2.9', true];
        // Unbounded.
        yield 'star meets everything' => ['*', '9.9', true];
        yield 'empty meets everything' => ['', '9.9', true];
        yield 'vers star meets everything' => ['vers:intdot/*', '1.0', true];
        yield 'garbage is fail-open' => ['latest firmware', '1.0', true];
    }

    #[DataProvider('intersections')]
    public function testIntersects(string $rangeText, string $inventory, bool $expected): void
    {
        $inv = ComparableVersion::fromString($inventory);
        self::assertNotNull($inv);
        self::assertSame($expected, VersionRange::fromText($rangeText)->intersects($inv), $rangeText.' vs '.$inventory);
    }

    public function testUnreadableTextIsFailOpenAndKeepsTheRawText(): void
    {
        $range = VersionRange::fromText('latest firmware');
        self::assertTrue($range->isUnbounded());
        self::assertSame('latest firmware', $range->parseFailure);
        self::assertStringContainsString('unreadable', $range->describe());
    }

    public function testStatedWildcardIsNotAParseFailure(): void
    {
        self::assertNull(VersionRange::fromText('*')->parseFailure);
        self::assertNull(VersionRange::fromText('')->parseFailure);
        self::assertNull(VersionRange::fromText('vers:intdot/*')->parseFailure);
    }

    public function testDescribeRendersBounds(): void
    {
        self::assertSame('<7.6.4', VersionRange::fromText('<7.6.4')->describe());
        self::assertSame('>=3.1.6 <3.1.7', VersionRange::fromText('vers:intdot/>=3.1.6|<3.1.7')->describe());
        self::assertSame('>=7.6.3 <7.6.4', VersionRange::fromText('7.6.3')->describe());
        self::assertSame('all versions', VersionRange::fromText('*')->describe());
    }

    public function testFixedFromIsTheExclusiveUpperBoundOnly(): void
    {
        self::assertSame('7.6.4', (string) VersionRange::fromText('<7.6.4')->fixedFrom());
        self::assertSame('3.1.7', (string) VersionRange::fromText('vers:intdot/>=3.1.6|<3.1.7')->fixedFrom());
        self::assertNull(VersionRange::fromText('<=2026.1.0')->fixedFrom(), 'an inclusive bound says nothing about the fix');
        self::assertNull(VersionRange::fromText('>=3.1.6')->fixedFrom());
        self::assertNull(VersionRange::fromText('*')->fixedFrom());
    }

    public function testIsExactlyRequiresSegmentEquality(): void
    {
        $inv763 = ComparableVersion::fromString('7.6.3');
        $inv76 = ComparableVersion::fromString('7.6');
        self::assertNotNull($inv763);
        self::assertNotNull($inv76);
        self::assertTrue(VersionRange::fromText('7.6.3')->isExactly($inv763));
        self::assertFalse(VersionRange::fromText('7.6.3')->isExactly($inv76));
        self::assertFalse(VersionRange::fromText('<7.6.4')->isExactly($inv763));
    }
}
