<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

/**
 * The set of versions an advisory names as affected, parsed from a CSAF branch name (#134, D5).
 *
 * Accepted shapes, in this order:
 *  1. canonical `vers:<scheme>/<constraint>|<constraint>…` (CSAF 2.0 §3.1.4.2, Siemens);
 *  2. a "vers-like specifier": comparator tokens such as `<7.6.4`, `>= V3.1.6 < V3.1.7`,
 *     `All versions < V3.0.29` (BSI, SICK, Schneider) — joined by `|`, `,`, `and` or whitespace;
 *  3. a single version (`7.6.3`, `core 1.28.3`, `V2.9`) → the prefix interval of that version;
 *  4. `*`, an empty string, or anything with no version-looking token at all → ALL versions.
 *
 * Fail-open by decision: a range that cannot be read still matches every inventory version, and
 * `parseFailure` carries the raw text so the reason is visible in the Advisory tab and the log.
 *
 * Internally a range is a list of intervals; `vers` allows several disjoint ones
 * (`>=1.0|<1.5|>=2.0|<2.5`). Constraints are sorted by version and paired the way the vers
 * specification prescribes: a lower bound opens an interval, the next upper bound closes it.
 */
final readonly class VersionRange
{
    /** Matches one comparator + version token; `V`/`v` prefixes and inner spaces are tolerated. */
    private const string CONSTRAINT_PATTERN = '/(<=|>=|==|!=|<|>|=)\s*[vV]?\s*(\d+(?:\.\d+)*)/';

    /** A `V`-prefixed version word (`V2.9`): the strongest signal when a product name also carries model numbers. */
    private const string PREFIXED_VERSION_PATTERN = '/(?<![A-Za-z0-9.\-])[vV](\d+(?:\.\d+)*)(?![A-Za-z0-9.])/';

    /** A bare version word: not glued to a letter/digit/dot/dash on the left, not to a letter/digit/dot on the right. */
    private const string BARE_VERSION_PATTERN = '/(?<![A-Za-z0-9.\-])(\d+(?:\.\d+)*)(?![A-Za-z0-9.])/';

    /**
     * @param list<array{lower: ComparableVersion|null, lowerInclusive: bool, upper: ComparableVersion|null, upperInclusive: bool}> $intervals empty = every version
     */
    private function __construct(
        public array $intervals,
        public string $raw,
        public ?string $parseFailure,
    ) {
    }

    public static function all(string $raw = '*'): self
    {
        return new self([], $raw, null);
    }

    public static function fromText(?string $text): self
    {
        $raw = trim((string) $text);
        if ('' === $raw || '*' === $raw) {
            return self::all($raw);
        }

        $body = $raw;
        if (1 === preg_match('~^vers:[^/]*/(.*)$~i', $raw, $m)) {
            $body = trim($m[1]);
            if ('' === $body || '*' === $body) {
                return self::all($raw);
            }
        }

        $body = str_replace(['≤', '≥'], ['<=', '>='], $body);

        if (preg_match_all(self::CONSTRAINT_PATTERN, $body, $matches, \PREG_SET_ORDER) > 0) {
            $constraints = [];
            foreach ($matches as $match) {
                $constraints[] = ['op' => $match[1], 'version' => ComparableVersion::fromString($match[2])];
            }
            $intervals = self::pair($constraints);
            if ([] !== $intervals) {
                return new self($intervals, $raw, null);
            }
        }

        foreach ([self::PREFIXED_VERSION_PATTERN, self::BARE_VERSION_PATTERN] as $pattern) {
            if (1 === preg_match($pattern, $body, $m)) {
                $version = ComparableVersion::fromString($m[1]);
                if (null !== $version) {
                    return self::single($version, $raw);
                }
            }
        }

        return new self([], $raw, $raw);
    }

    /**
     * A single stated version, read as its prefix interval: advisory `7.6.3` meets inventory
     * `7.6` and inventory `7.6.3`, but not `7.6.4`.
     */
    public static function single(ComparableVersion $version, ?string $raw = null): self
    {
        return new self([[
            'lower' => $version,
            'lowerInclusive' => true,
            'upper' => $version->prefixUpperBound(),
            'upperInclusive' => false,
        ]], $raw ?? (string) $version, null);
    }

    /**
     * @param list<array{op: string, version: ComparableVersion|null}> $constraints
     *
     * @return list<array{lower: ComparableVersion|null, lowerInclusive: bool, upper: ComparableVersion|null, upperInclusive: bool}>
     */
    private static function pair(array $constraints): array
    {
        $usable = array_values(array_filter($constraints, static fn (array $c) => null !== $c['version'] && '!=' !== $c['op']));
        usort($usable, static fn (array $a, array $b) => $a['version']->compareTo($b['version']));

        $intervals = [];
        $open = null;
        foreach ($usable as $c) {
            /** @var ComparableVersion $v */
            $v = $c['version'];
            switch ($c['op']) {
                case '>':
                case '>=':
                    if (null !== $open) {
                        $intervals[] = ['lower' => $open['version'], 'lowerInclusive' => '>=' === $open['op'], 'upper' => null, 'upperInclusive' => false];
                    }
                    $open = $c;
                    break;
                case '<':
                case '<=':
                    $intervals[] = [
                        'lower' => $open['version'] ?? null,
                        'lowerInclusive' => null !== $open && '>=' === $open['op'],
                        'upper' => $v,
                        'upperInclusive' => '<=' === $c['op'],
                    ];
                    $open = null;
                    break;
                default: // '=' / '=='
                    $intervals[] = ['lower' => $v, 'lowerInclusive' => true, 'upper' => $v->prefixUpperBound(), 'upperInclusive' => false];
            }
        }
        if (null !== $open) {
            $intervals[] = ['lower' => $open['version'], 'lowerInclusive' => '>=' === $open['op'], 'upper' => null, 'upperInclusive' => false];
        }

        return $intervals;
    }

    /**
     * True when every version is covered — either stated so (`*`) or because nothing could be
     * read (see {@see $parseFailure}).
     */
    public function isUnbounded(): bool
    {
        return [] === $this->intervals;
    }

    /**
     * Does the inventory's prefix interval overlap any interval of this range?
     *
     * Inventory `7.6` is [7.6, 7.7). Advisory `<7.6.4` is (-∞, 7.6.4): they overlap → affected.
     * Advisory `<7.4.9` does not reach 7.6 → not affected. Inventory `7.6.5` is [7.6.5, 7.6.6)
     * and lies past `<7.6.4` → not affected.
     */
    public function intersects(ComparableVersion $inventory): bool
    {
        if ([] === $this->intervals) {
            return true;
        }
        $invLower = $inventory;
        $invUpper = $inventory->prefixUpperBound(); // exclusive

        foreach ($this->intervals as $interval) {
            // The advisory's lower bound must lie below the inventory's exclusive upper bound.
            if (null !== $interval['lower'] && $interval['lower']->compareTo($invUpper) >= 0) {
                continue;
            }
            // The advisory's upper bound must lie above (or, if inclusive, on) the inventory's lower bound.
            if (null !== $interval['upper']) {
                $cmp = $interval['upper']->compareTo($invLower);
                if ($cmp < 0 || (0 === $cmp && !$interval['upperInclusive'])) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Is the range exactly one stated version equal to the inventory version (segment for segment)?
     */
    public function isExactly(ComparableVersion $inventory): bool
    {
        if (1 !== \count($this->intervals)) {
            return false;
        }
        $i = $this->intervals[0];

        return null !== $i['lower'] && null !== $i['upper']
            && $i['lowerInclusive'] && !$i['upperInclusive']
            && $i['lower']->equals($inventory)
            && $i['upper']->equals($inventory->prefixUpperBound());
    }

    /**
     * The smallest version the advisory reports as no longer affected, if the range has an
     * exclusive upper bound: `<7.6.4` → 7.6.4. Feeds the "fixed from …" note. Null for inclusive
     * upper bounds (`<=2026.1.0` says nothing about what is fixed) and open ranges.
     */
    public function fixedFrom(): ?ComparableVersion
    {
        $best = null;
        foreach ($this->intervals as $interval) {
            if (null === $interval['upper'] || $interval['upperInclusive']) {
                continue;
            }
            if (null === $best || $interval['upper']->compareTo($best) > 0) {
                $best = $interval['upper'];
            }
        }

        return $best;
    }

    /**
     * Human-readable form for logs, the Advisory tab and the recheck report.
     */
    public function describe(): string
    {
        if (null !== $this->parseFailure) {
            return \sprintf('all versions (unreadable: "%s")', $this->parseFailure);
        }
        if ([] === $this->intervals) {
            return 'all versions';
        }
        $parts = [];
        foreach ($this->intervals as $i) {
            $bits = [];
            if (null !== $i['lower']) {
                $bits[] = ($i['lowerInclusive'] ? '>=' : '>').$i['lower'];
            }
            if (null !== $i['upper']) {
                $bits[] = ($i['upperInclusive'] ? '<=' : '<').$i['upper'];
            }
            $parts[] = implode(' ', $bits);
        }

        return implode(' | ', $parts);
    }
}
