<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Recheck;

/**
 * Outcome of re-evaluating one (vulnerability, software) pair under the #134 matcher (D10).
 */
enum RecheckVerdict: string
{
    /** Keys and version still meet the stored (or re-downloaded) advisory range. */
    case StillMatches = 'still-matches';

    /** Keys match, the inventory version lies outside the affected range — the old CPE wildcard would have reported it. */
    case VersionOutside = 'version-outside';

    /** The row carries no advisory evidence (`csafSources`); there is nothing to re-evaluate it against. */
    case NoEvidence = 'no-evidence';

    /** No affected product of the advisory has the software's keys (or aliases). */
    case NoKeyMatch = 'no-key-match';

    /** The row carries no stored ranges and the document could not be downloaded or parsed. */
    case Unknown = 'unknown';
}
