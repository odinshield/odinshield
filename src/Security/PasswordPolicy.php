<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one place that decides whether a password is acceptable (#239) — the form constraint, the
 * console and the field hints all ask here, so they can never disagree.
 *
 * Two ways through, after the BSI recommendation (config/packages/password_policy.yaml):
 *  - short and complex: at least `min_length` characters with every required character class;
 *  - passphrase: at least `passphrase_min_length` characters with `passphrase_min_classes`
 *    different classes, e.g. several words separated by spaces.
 *
 * Character classes: upper- and lower-case letters (Unicode, so "Ä"/"ß" count — #188), ASCII
 * digits, and "special" = any printable ASCII punctuation or the space. Non-ASCII symbols (€, §)
 * are allowed but count for no class: they are missing from many keyboard layouts.
 */
final readonly class PasswordPolicy
{
    private const string SPECIAL = '/[\x20-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/';

    /** Rule name => pattern, in the order the hints and messages list them. */
    private const array CLASSES = [
        'uppercase' => '/\p{Lu}/u',
        'lowercase' => '/\p{Ll}/u',
        'digit' => '/[0-9]/',
        'special' => self::SPECIAL,
    ];

    /**
     * @param array{
     *     min_length: int,
     *     require_uppercase: bool,
     *     require_lowercase: bool,
     *     require_digit: bool,
     *     require_special: bool,
     *     passphrase_min_length: int,
     *     passphrase_min_classes: int,
     * } $policy
     */
    public function __construct(
        #[Autowire('%app.password_policy%')]
        private array $policy,
    ) {
    }

    /**
     * What is missing, as validator message keys (domain `validators`) with their parameters.
     * Empty when either way through is satisfied.
     *
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public function violations(string $password): array
    {
        if ($this->meetsShortRule($password) || $this->meetsPassphraseRule($password)) {
            return [];
        }

        // Report what the short rule still needs — one message per gap (L25) — and point at the
        // passphrase as the alternative, so nobody is left thinking the list is the only way.
        $violations = [];
        if (mb_strlen($password) < $this->policy['min_length']) {
            $violations[] = ['user.password.too_short', ['{{ min_length }}' => (string) $this->policy['min_length']]];
        }
        foreach ($this->requiredClasses() as $rule) {
            if (1 !== preg_match(self::CLASSES[$rule], $password)) {
                $violations[] = ['user.password.missing_'.$rule, []];
            }
        }
        if ($this->passphraseEnabled()) {
            $violations[] = ['user.password.passphrase_alternative', $this->passphraseParameters('{{ %s }}')];
        }

        return $violations;
    }

    /**
     * The field hint, translated in the current locale: both ways through, the passphrase as the
     * recommendation, and the BSI advice to avoid umlauts.
     */
    public function hint(TranslatorInterface $translator): string
    {
        $rules = array_map(
            static fn (string $rule): string => $translator->trans('password_policy.rule.'.$rule),
            $this->requiredClasses(),
        );
        $parameters = [
            '%min_length%' => (string) $this->policy['min_length'],
            '%rules%' => match (\count($rules)) {
                0 => '—',
                1 => $rules[0],
                // "a, b and c" — the last rule itself contains "or" (punctuation or space).
                default => implode(', ', \array_slice($rules, 0, -1)).' '.$translator->trans('password_policy.and').' '.$rules[\count($rules) - 1],
            },
            ...$this->passphraseParameters('%%%s%%'),
        ];

        return $translator->trans($this->passphraseEnabled() ? 'password_policy.hint' : 'password_policy.hint_short_only', $parameters)
            .' '.$translator->trans('password_policy.umlauts');
    }

    private function meetsShortRule(string $password): bool
    {
        if (mb_strlen($password) < $this->policy['min_length']) {
            return false;
        }
        foreach ($this->requiredClasses() as $rule) {
            if (1 !== preg_match(self::CLASSES[$rule], $password)) {
                return false;
            }
        }

        return true;
    }

    private function meetsPassphraseRule(string $password): bool
    {
        if (!$this->passphraseEnabled() || mb_strlen($password) < $this->policy['passphrase_min_length']) {
            return false;
        }

        $classes = 0;
        foreach (self::CLASSES as $pattern) {
            $classes += 1 === preg_match($pattern, $password) ? 1 : 0;
        }

        return $classes >= $this->policy['passphrase_min_classes'];
    }

    private function passphraseEnabled(): bool
    {
        return $this->policy['passphrase_min_length'] > 0;
    }

    /**
     * @return list<string>
     */
    private function requiredClasses(): array
    {
        return array_values(array_filter(
            array_keys(self::CLASSES),
            fn (string $rule): bool => $this->policy['require_'.$rule],
        ));
    }

    /**
     * @return array<string, string>
     */
    private function passphraseParameters(string $placeholder): array
    {
        return [
            \sprintf($placeholder, 'passphrase_length') => (string) $this->policy['passphrase_min_length'],
            \sprintf($placeholder, 'passphrase_classes') => (string) $this->policy['passphrase_min_classes'],
        ];
    }
}
