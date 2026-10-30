<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\UpdateCheck;

use App\Service\UpdateCheck\VersionComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #223: when does a published version count as an update for the running one?
 */
final class VersionComparatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'newer patch' => ['2.2.9', '2.2.8', true];
        yield 'newer minor' => ['2.3.0', '2.2.8', true];
        yield 'numeric, not lexical' => ['2.10.0', '2.9.0', true];
        yield 'same version' => ['2.2.8', '2.2.8', false];
        yield 'older version' => ['2.2.7', '2.2.8', false];
        yield 'rc for a stable instance' => ['2.3.0-rc.1', '2.2.8', false];
        yield 'rc for an rc instance' => ['2.3.0-rc.2', '2.3.0-rc.1', true];
        yield 'rc ordering is numeric' => ['2.3.0-rc.10', '2.3.0-rc.2', true];
        yield 'final after its rc' => ['2.3.0', '2.3.0-rc.3', true];
        yield 'rc behind the final' => ['2.3.0-rc.3', '2.3.0', false];
        yield 'garbled candidate' => ['latest', '2.2.8', false];
        yield 'leading v is not a version' => ['v2.3.0', '2.2.8', false];
        yield 'dev fallback sees finals' => ['2.3.0', '0.0.0-dev', true];
    }

    #[DataProvider('cases')]
    public function testIsNewer(string $candidate, string $current, bool $expected): void
    {
        self::assertSame($expected, VersionComparator::isNewer($candidate, $current));
    }
}
