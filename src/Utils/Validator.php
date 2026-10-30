<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Utils;

use App\Security\PasswordPolicy;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Validator
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Normalises before validating (#140): usernames are stored lowercase, so an interactively
     * entered "MMustermann" is accepted and becomes "mmustermann" rather than being rejected.
     */
    public function validateUsername(?string $username): string
    {
        if (empty($username)) {
            throw new InvalidArgumentException('The username can not be empty.');
        }

        $username = mb_strtolower(trim($username));

        if (1 !== preg_match('/^[a-z_0-9\-@.]+$/', $username)) {
            throw new InvalidArgumentException('The username must contain only latin characters, underscores, dashes, digits and @.');
        }

        return $username;
    }

    public function validatePassword(?string $plainPassword): string
    {
        if (empty($plainPassword)) {
            throw new InvalidArgumentException('The password can not be empty.');
        }

        // #239: the same PasswordPolicy as the forms — one rule set, no second copy here. The
        // console speaks English, so the validator messages are rendered in English.
        $violations = $this->passwordPolicy->violations($plainPassword);
        if ([] !== $violations) {
            throw new InvalidArgumentException(implode(' ', array_map(fn (array $violation): string => $this->translator->trans($violation[0], $violation[1], 'validators', 'en'), $violations)));
        }

        return $plainPassword;
    }

    public function validateEmail(?string $email): string
    {
        if (empty($email)) {
            throw new InvalidArgumentException('The email can not be empty.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format.');
        }

        if (1 !== preg_match('/^[a-zA-Z_0-9\-@.]+$/', $email)) {
            throw new InvalidArgumentException('The E-Mail must contain only lowercase/ uppercase latin characters, underscores, dashes and digits.');
        }

        return $email;
    }

    public function validateName(?string $name): string
    {
        if (empty($name)) {
            throw new InvalidArgumentException('The [first] name can not be empty.');
        }

        if (1 !== preg_match('/^[a-zA-Z0-9\-_]+$/', $name)) {
            throw new InvalidArgumentException('The [first] name must contain only lowercase/ uppercase latin characters, underscores, dashes and digits.');
        }

        return $name;
    }
}
