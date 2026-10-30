<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * SEC-018 / #140 — User::setUsername() is the single choke point that keeps stored usernames
 * lowercase, so that the binary unique index on the column actually prevents duplicates.
 */
final class UserUsernameNormalizationTest extends TestCase
{
    public function testUppercaseInputIsStoredLowercase(): void
    {
        $user = new User();
        $user->setUsername('MMustermann');

        self::assertSame('mmustermann', $user->getUsername());
        self::assertSame('mmustermann', $user->getUserIdentifier());
    }

    public function testSurroundingWhitespaceIsRemoved(): void
    {
        $user = new User();
        $user->setUsername("  MMustermann\t");

        self::assertSame('mmustermann', $user->getUsername());
    }

    public function testUmlautsAreLowercasedAsWell(): void
    {
        // mb_strtolower, not strtolower: a byte-wise lowercase would leave "Ä" untouched and
        // reintroduce exactly the duplicate this normalisation prevents.
        $user = new User();
        $user->setUsername('MÜLLER');

        self::assertSame('müller', $user->getUsername());
    }

    public function testNullStaysNull(): void
    {
        $user = new User();
        $user->setUsername(null);

        self::assertNull($user->getUsername());
    }
}
