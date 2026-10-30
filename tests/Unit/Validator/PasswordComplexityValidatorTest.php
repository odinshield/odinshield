<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Validator;

use App\Security\PasswordPolicy;
use App\Validator\Constraints\PasswordComplexity;
use App\Validator\Constraints\PasswordComplexityValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<PasswordComplexityValidator>
 */
final class PasswordComplexityValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new PasswordComplexityValidator(new PasswordPolicy([
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_digit' => true,
            'require_special' => true,
            'passphrase_min_length' => 20,
            'passphrase_min_classes' => 2,
        ]));
    }

    /**
     * #188: a German capital or small letter with umlaut counts as upper/lower case — the ASCII
     * classes rejected "Ärger2026!ok" for lacking an upper-case letter.
     *
     * @return iterable<string, array{string}>
     */
    public static function unicodeLetters(): iterable
    {
        yield 'umlaut is the only capital' => ['Ärger2026!ok'];
        yield 'umlaut is the only small letter' => ['ÄRGER2026!Öß'];
    }

    #[DataProvider('unicodeLetters')]
    public function testUnicodeLettersSatisfyTheCaseRules(string $password): void
    {
        $this->validator->validate($password, new PasswordComplexity());

        $this->assertNoViolation();
    }

    public function testMissingCapitalIsStillReported(): void
    {
        $this->validator->validate('ärger2026!ok', new PasswordComplexity());

        $this->buildViolation('user.password.missing_uppercase')
            ->buildNextViolation('user.password.passphrase_alternative')
            ->setParameter('{{ passphrase_length }}', '20')
            ->setParameter('{{ passphrase_classes }}', '2')
            ->assertRaised();
    }
}
