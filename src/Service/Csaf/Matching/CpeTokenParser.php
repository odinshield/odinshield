<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

/**
 * Reads the vendor, product and version components out of a CPE URI (#134, D3/D7).
 *
 * Accepts both forms:
 *   - CPE 2.3 — `cpe:2.3:<part>:<vendor>:<product>:<version>:…`
 *   - CPE 2.2 — `cpe:/<part>:<vendor>:<product>:<version>:…`
 *
 * The part (`a`/`o`/`h`) is deliberately not returned: it is never compared. A CPE part typo
 * (`a` where the provider writes `o`) used to hide a product silently — see F5 in the plan.
 * Vendor and product are returned normalised via {@see KeyNormaliser}; a wildcard (`*`, `-`, empty)
 * in either yields null, because a CPE without identity cannot identify anything.
 */
final class CpeTokenParser
{
    /**
     * @return array{vendor: string, product: string, version: string|null}|null
     */
    public function parseTokens(string $cpe): ?array
    {
        $cpe = trim($cpe);
        if (str_starts_with($cpe, 'cpe:2.3:')) {
            $parts = $this->splitUnescaped(substr($cpe, \strlen('cpe:2.3:')));
        } elseif (str_starts_with($cpe, 'cpe:/')) {
            $parts = $this->splitUnescaped(substr($cpe, \strlen('cpe:/')));
        } else {
            return null;
        }

        $vendor = KeyNormaliser::normalise($this->unescape($parts[1] ?? ''));
        $product = KeyNormaliser::normalise($this->unescape($parts[2] ?? ''));
        if ('' === $vendor || '' === $product) {
            return null;
        }

        $version = $this->unescape($parts[3] ?? '');
        if ('' === $version || '*' === $version || '-' === $version) {
            $version = null;
        }

        return ['vendor' => $vendor, 'product' => $product, 'version' => $version];
    }

    private function unescape(string $component): string
    {
        return (string) preg_replace('/\\\\(.)/', '$1', $component);
    }

    /**
     * Split on `:` but ignore backslash-escaped occurrences (CPE 2.3 escaping).
     *
     * @return list<string>
     */
    private function splitUnescaped(string $input): array
    {
        $out = [];
        $buf = '';
        $len = \strlen($input);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $input[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $buf .= $ch.$input[$i + 1];
                ++$i;
                continue;
            }
            if (':' === $ch) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $out[] = $buf;

        return $out;
    }
}
