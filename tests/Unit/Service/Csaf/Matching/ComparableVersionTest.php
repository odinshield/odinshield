<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Matching;

use App\Service\Csaf\Matching\ComparableVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparableVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function leadingNumericParts(): iterable
    {
        yield 'plain' => ['7.6.3', '7.6.3'];
        yield 'major/minor' => ['7.6', '7.6'];
        yield 'build suffix' => ['7.6.3 build 1234', '7.6.3'];
        yield 'year release' => ['2024 R2', '2024'];
        yield 'windows style' => ['11 23H2', '11'];
        yield 'v prefix' => ['V3.1.6', '3.1.6'];
        yield 'leading whitespace' => ['  2.9', '2.9'];
        yield 'dash suffix' => ['2026.1.0-rc1', '2026.1.0'];
        yield 'no numeric start' => ['latest', null];
        yield 'numeric not at the start' => ['Build 2026-A', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('leadingNumericParts')]
    public function testFromStringTakesTheLeadingDottedNumericPart(string $input, ?string $expected): void
    {
        $v = ComparableVersion::fromString($input);
        self::assertSame($expected, null === $v ? null : (string) $v);
    }

    public function testNullInputHasNoComparableVersion(): void
    {
        self::assertNull(ComparableVersion::fromString(null));
    }

    public function testCompareIsNumericPerSegmentNotLexical(): void
    {
        $a = ComparableVersion::fromString('7.6.10');
        $b = ComparableVersion::fromString('7.6.9');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertGreaterThan(0, $a->compareTo($b), '10 > 9 even though "1" < "9" lexically');
        self::assertLessThan(0, $b->compareTo($a));
    }

    public function testMissingSegmentsCountAsZeroForPointComparison(): void
    {
        $short = ComparableVersion::fromString('7.6');
        $long = ComparableVersion::fromString('7.6.0');
        self::assertNotNull($short);
        self::assertNotNull($long);
        self::assertSame(0, $short->compareTo($long));
        self::assertFalse($short->equals($long), 'equals() is segment-exact: 7.6 is a prefix, 7.6.0 a point');
    }

    public function testPrefixUpperBoundIncrementsTheLastSegment(): void
    {
        self::assertSame('7.7', (string) ComparableVersion::fromString('7.6')?->prefixUpperBound());
        self::assertSame('8', (string) ComparableVersion::fromString('7')?->prefixUpperBound());
        self::assertSame('7.6.4', (string) ComparableVersion::fromString('7.6.3')?->prefixUpperBound());
    }
}
