<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service;

use App\Service\MailNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #269: the new address in the change notice is partly masked — recognisable for the owner,
 * not handed out in full to whoever reads the old mailbox.
 */
final class MailNotifierMaskEmailTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'long local part keeps two' => ['andre@example.com', 'an***@example.com'];
        yield 'short local part keeps one' => ['bob@example.com', 'b***@example.com'];
        yield 'single character' => ['a@example.com', 'a***@example.com'];
        yield 'multibyte' => ['jörg.müller@example.de', 'jö***@example.de'];
        yield 'no at sign' => ['not-an-address', '***'];
        yield 'empty local part' => ['@example.com', '***'];
    }

    #[DataProvider('addresses')]
    public function testMask(string $email, string $expected): void
    {
        self::assertSame($expected, MailNotifier::maskEmail($email));
    }
}
