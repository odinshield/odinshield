<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Matching;

use App\Service\Csaf\Matching\KeyNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyNormaliserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalisations(): iterable
    {
        yield 'already a key' => ['fortinet', 'fortinet'];
        yield 'branch name with company suffix' => ['SICK AG', 'sick_ag'];
        yield 'two words' => ['Red Hat', 'red_hat'];
        yield 'CPE 2.3 escape' => ['\\.net_framework', 'net_framework'];
        yield 'umlaut and punctuation' => ['Müller & Söhne GmbH', 'm_ller_s_hne_gmbh'];
        yield 'leading and trailing junk' => ['  --OPNsense--  ', 'opnsense'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('normalisations')]
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, KeyNormaliser::normalise($input));
    }

    public function testEqualsIgnoresSpellingButNotContent(): void
    {
        self::assertTrue(KeyNormaliser::equals('Fortinet', 'fortinet'));
        self::assertTrue(KeyNormaliser::equals('Open Source', 'open_source'));
        self::assertFalse(KeyNormaliser::equals('fortinet', 'fortinet_net'), 'containment is not equality');
        self::assertFalse(KeyNormaliser::equals('', ''), 'empty never matches — a missing key must not pair with a missing name');
    }

    public function testContainsEitherWayFlagsAliasCandidatesOnly(): void
    {
        self::assertTrue(KeyNormaliser::containsEitherWay('sick', 'SICK AG'));
        self::assertTrue(KeyNormaliser::containsEitherWay('SICK AG', 'sick'));
        self::assertTrue(KeyNormaliser::containsEitherWay('fortinet_net', 'fortinet'));
        self::assertFalse(KeyNormaliser::containsEitherWay('sick', 'sick'), 'equal tokens are a match, not a near-miss');
        self::assertFalse(KeyNormaliser::containsEitherWay('sick', 'sickle'), 'a substring inside a word is not a word match');
        self::assertFalse(KeyNormaliser::containsEitherWay('', 'sick'));
    }
}
