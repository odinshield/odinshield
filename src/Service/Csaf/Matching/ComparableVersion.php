<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

/**
 * The comparable part of a version string: its leading dotted-numeric prefix (#134, D4).
 *
 * `7.6.3 build 1234` → 7.6.3, `2024 R2` → 2024, `V3.1.6` → 3.1.6, `11 23H2` → 11. A string with
 * no numeric start (`latest`, `Build 2026-A`) has no comparable version — `fromString()` returns
 * null and the caller treats the software as "any version" (fail-open, visible warning).
 *
 * Comparison is segment-wise numeric; `version_compare()` is deliberately not used because its
 * letter handling turns `7.6.3 build 1234` into something that sorts after `7.6.30`. Missing
 * trailing segments count as zero, so 7.6 == 7.6.0 as a point.
 *
 * As an *inventory* value, a version denotes a prefix interval: `7.6` covers every 7.6.x, i.e.
 * the half-open interval [7.6, 7.7). `7` covers [7, 8). That is what lets an inventory kept at
 * major/minor level meet an advisory bound stated at patch level (D1).
 */
final readonly class ComparableVersion implements \Stringable
{
    /**
     * @param non-empty-list<int> $segments
     */
    private function __construct(
        public array $segments,
    ) {
    }

    public static function fromString(?string $version): ?self
    {
        if (null === $version) {
            return null;
        }
        if (1 !== preg_match('/^\s*[vV]?(\d+(?:\.\d+)*)/', $version, $m)) {
            return null;
        }
        $segments = array_map(intval(...), explode('.', $m[1]));

        return new self($segments);
    }

    /**
     * @param non-empty-list<int> $segments
     */
    public static function fromSegments(array $segments): self
    {
        return new self($segments);
    }

    /**
     * Segment-wise numeric comparison; shorter operands are padded with zeros.
     */
    public function compareTo(self $other): int
    {
        $len = max(\count($this->segments), \count($other->segments));
        for ($i = 0; $i < $len; ++$i) {
            $a = $this->segments[$i] ?? 0;
            $b = $other->segments[$i] ?? 0;
            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        return 0;
    }

    public function equals(self $other): bool
    {
        return $this->segments === $other->segments;
    }

    /**
     * Exclusive upper bound of the prefix interval this version denotes: 7.6 → 7.7, 7 → 8,
     * 7.6.3 → 7.6.4.
     */
    public function prefixUpperBound(): self
    {
        $segments = $this->segments;
        $last = \count($segments) - 1;
        ++$segments[$last];

        return new self($segments);
    }

    public function __toString(): string
    {
        return implode('.', $this->segments);
    }
}
