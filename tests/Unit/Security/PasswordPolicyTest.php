<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Security;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #239 — BSI shape 1 (short and complex) or shape 2 (passphrase), ASCII punctuation and the space
 * as special characters, umlauts counting as letters (#188).
 */
final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'shape 1 with a full stop' => ['Sommer2026.'];
        yield 'shape 1 with an underscore' => ['Sommer_2026x'];
        yield 'shape 1 with a space' => ['Sommer 2026x'];
        yield 'shape 1, umlaut as the only capital' => ['Ärger2026!ok'];
        yield 'shape 2: words and spaces' => ['korrekt pferd batterie heftklammer blau'];
        yield 'shape 2: exactly 20 characters, two types' => ['abcdefghij klmnopqrs'];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $password): void
    {
        self::assertSame([], $this->policy()->violations($password));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejected(): iterable
    {
        yield 'short passphrase' => ['korrekt pferd blau', ['user.password.missing_uppercase', 'user.password.missing_digit', 'user.password.passphrase_alternative']];
        yield 'long, but one type only' => ['korrektpferdbatterieheftklammer', ['user.password.missing_uppercase', 'user.password.missing_digit', 'user.password.missing_special', 'user.password.passphrase_alternative']];
        yield 'euro sign is no special character' => ['Sommer2026€', ['user.password.missing_special', 'user.password.passphrase_alternative']];
        yield 'too short' => ['Ab1!', ['user.password.too_short', 'user.password.passphrase_alternative']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('rejected')]
    public function testRejectedWithEveryGapAndThePassphraseHint(string $password, array $expected): void
    {
        self::assertSame($expected, array_column($this->policy()->violations($password), 0));
    }

    public function testPassphraseCanBeSwitchedOff(): void
    {
        $policy = $this->policy(passphraseMinLength: 0);

        self::assertNotSame([], $policy->violations('korrekt pferd batterie heftklammer blau'));
        self::assertNotContains('user.password.passphrase_alternative', array_column($policy->violations('ab'), 0));
    }

    private function policy(int $passphraseMinLength = 20): PasswordPolicy
    {
        return new PasswordPolicy([
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_digit' => true,
            'require_special' => true,
            'passphrase_min_length' => $passphraseMinLength,
            'passphrase_min_classes' => 2,
        ]);
    }
}
