<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Audit;

use App\Audit\AuditCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SEC-017 / #194: every cell a spreadsheet could read as a formula gets an apostrophe prefix.
 */
final class AuditCsvExporterNeutraliseTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function triggers(): iterable
    {
        yield 'equals' => ['=HYPERLINK("https://evil.test","x")'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-2+3'];
        yield 'at' => ['@SUM(A1:A2)'];
        yield 'tab' => ["\tanything"];
        yield 'carriage return' => ["\ranything"];
        yield 'formula behind blanks' => ['   =1+1'];
        yield 'formula behind a line break' => ["\n=1+1"];
    }

    #[DataProvider('triggers')]
    public function testFormulaTriggerIsPrefixed(string $value): void
    {
        self::assertSame("'".$value, AuditCsvExporter::neutralise($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmless(): iterable
    {
        yield 'empty' => [''];
        yield 'plain text' => ['FortiOS 7.6'];
        yield 'formula char inside' => ['a=b+c'];
        yield 'only blanks' => ['   '];
        yield 'leading line break before text' => ["\nnote"];
    }

    #[DataProvider('harmless')]
    public function testHarmlessValueIsUnchanged(string $value): void
    {
        self::assertSame($value, AuditCsvExporter::neutralise($value));
    }
}
