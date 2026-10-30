<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Import;

/**
 * Read volume of one CSAF provider from the document ledger (issue #319) — what makes a silent
 * loss visible. Under the old fetch window, #313 lost nearly every BSI advisory for weeks while
 * every run reported success; with these figures it would have shown on the first day as "the feed
 * listed 400 entries this week, the import handled none".
 */
final readonly class ImportLedgerFigures
{
    /**
     * @param int|null $recentFeedEntries entries the feeds listed in the recent window, as of the last
     *                                    successful run; null before the first ledger run
     * @param int|null $pending           documents still pending after the last successful run
     * @param list<array{url: string, lastError: ?string, handledAt: \DateTimeImmutable}> $givenUpDocuments the newest give-ups, at most a handful
     */
    public function __construct(
        public ?int $recentFeedEntries,
        public int $handledRecently,
        public int $processedRecently,
        public ?int $pending,
        public int $failing,
        public int $givenUp,
        public array $givenUpDocuments,
    ) {
    }

    /**
     * The feeds listed entries in the recent window, but the import handled none of them — the
     * shape of #313. Only meaningful for a provider that is enabled; the caller decides that.
     */
    public function readsNothing(): bool
    {
        return null !== $this->recentFeedEntries && $this->recentFeedEntries > 0 && 0 === $this->handledRecently;
    }
}
