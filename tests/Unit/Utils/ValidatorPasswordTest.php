<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Utils;

use App\Security\PasswordPolicy;
use App\Utils\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * #188/#239: the console check (odinshield:user:add) asks the same PasswordPolicy as the forms.
 */
final class ValidatorPasswordTest extends TestCase
{
    public function testUmlautCapitalIsAccepted(): void
    {
        self::assertSame('Ärger2026!ok', $this->validator()->validatePassword('Ärger2026!ok'));
    }

    public function testPassphraseIsAccepted(): void
    {
        self::assertSame('korrekt pferd batterie heftklammer blau', $this->validator()->validatePassword('korrekt pferd batterie heftklammer blau'));
    }

    public function testMissingCapitalIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uppercase');

        $this->validator()->validatePassword('ärger2026!ok');
    }

    private function validator(): Validator
    {
        return new Validator(new PasswordPolicy([
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_digit' => true,
            'require_special' => true,
            'passphrase_min_length' => 20,
            'passphrase_min_classes' => 2,
        ]), new IdentityTranslator());
    }
}
