<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Import;

/**
 * The one fact about advisory sources an ISB is entitled to (issue #63): how many are disrupted
 * and for how long — nothing else.
 *
 * Deliberately carries no provider key, no error message and no link. `/import-status` stays
 * ROLE_ADMIN-only: running the importers is an administration concern (issue #43, and the
 * "do not widen this gate" note on {@see \App\Controller\ImportStatusController}). What the ISB
 * owns is the fachliche statement "my information gathering has been incomplete for N days" —
 * a BSI-CS 093 core duty. Repairing it belongs to the admin.
 */
final readonly class ImportSourceHealth
{
    /**
     * @param int                     $disruptedCount        number of sources the circuit breaker has paused
     * @param \DateTimeImmutable|null $oldestSuccessOfBroken last successful run of the source that
     *                                                       has been down longest; null when none of
     *                                                       the disrupted sources ever succeeded
     */
    public function __construct(
        public int $disruptedCount,
        public ?\DateTimeImmutable $oldestSuccessOfBroken,
    ) {
    }

    /**
     * Whole days since the longest-running disruption last delivered, or null when that source
     * never succeeded at all — "never worked" is a different statement from "broken since N days"
     * and the template says so in its own words.
     */
    public function daysSinceOldestSuccess(?\DateTimeImmutable $now = null): ?int
    {
        if (null === $this->oldestSuccessOfBroken) {
            return null;
        }

        return $this->oldestSuccessOfBroken->diff($now ?? new \DateTimeImmutable())->days;
    }
}
