<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

/**
 * Normalises identity tokens so that the inventory's `vendorKey`/`productKey`, CPE vendor/product
 * components and free-text CSAF branch names ("SICK AG", "Open Source") can be compared for
 * equality (#134, D7).
 *
 * Rule: lowercase, every run of non-alphanumerics becomes a single `_`, leading/trailing `_`
 * trimmed. "SICK AG" → `sick_ag`, "Red Hat" → `red_hat`, `cpe:2.3` escape `\.net` → `net`.
 */
final class KeyNormaliser
{
    public static function normalise(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $collapsed = (string) preg_replace('/[^a-z0-9]+/', '_', $lower);

        return trim($collapsed, '_');
    }

    /**
     * Equality after normalisation. Empty strings never match anything — a missing key must not
     * pair with a missing branch name.
     */
    public static function equals(string $a, string $b): bool
    {
        $na = self::normalise($a);
        $nb = self::normalise($b);

        return '' !== $na && $na === $nb;
    }

    /**
     * Near-miss detection for the alias log (D7): one normalised token contains the other as a
     * whole `_`-delimited word sequence ("sick" ⊂ "sick_ag"). Deliberately NOT used for matching —
     * only for a notice that suggests an alias.
     */
    public static function containsEitherWay(string $a, string $b): bool
    {
        $na = self::normalise($a);
        $nb = self::normalise($b);
        if ('' === $na || '' === $nb || $na === $nb) {
            return false;
        }
        [$short, $long] = \strlen($na) <= \strlen($nb) ? [$na, $nb] : [$nb, $na];

        return 1 === preg_match('/(^|_)'.preg_quote($short, '/').'(_|$)/', $long);
    }
}
