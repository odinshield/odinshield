<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\UpdateCheck;

/**
 * #223: is a published version newer than the one running here?
 *
 * Versions are the tags without the "v": `2.3.0`, `2.3.0-rc.1`. A pre-release is only offered to
 * an instance that runs a pre-release itself — a stable installation is never pointed at an rc.
 * Anything that does not look like such a version is "not newer": a garbled version.json must
 * not produce a banner.
 */
final class VersionComparator
{
    private const string PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    public static function isValid(string $version): bool
    {
        return 1 === preg_match(self::PATTERN, $version);
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        if (!self::isValid($candidate) || !self::isValid($current)) {
            return false;
        }

        if (self::isPreRelease($candidate) && !self::isPreRelease($current)) {
            return false;
        }

        // version_compare orders "2.3.0-rc.1" < "2.3.0-rc.10" < "2.3.0" — exactly the tag scheme.
        return version_compare($candidate, $current, '>');
    }

    private static function isPreRelease(string $version): bool
    {
        return str_contains($version, '-');
    }
}
