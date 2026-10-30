<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Ledger;

use App\Enum\CsafDocumentOutcome;

/**
 * The two decisions the document ledger makes per run (issue #319), kept free of I/O so they can
 * be tested on their own: which feed entries are pending, and up to when a document's content has
 * already been seen (the reference for the rebuild check, #98).
 *
 * @phpstan-type FeedEntry array{url: string, updated: \DateTimeImmutable}
 * @phpstan-type HandledRow array{feedUpdated: \DateTimeImmutable, outcome: CsafDocumentOutcome, attempts: int, contentChangedAt: ?\DateTimeImmutable}
 */
final class DocumentLedger
{
    /**
     * The window of the read-volume figures on /import-status and of the "reads nothing" health
     * rule: what a feed listed and what the import handled in the last this many days.
     */
    public const int RECENT_DAYS = 7;

    /**
     * Pending = no record, or the feed now shows a newer stamp than the record, or the last
     * attempt failed and the attempt limit is not reached. Nothing else — in particular no
     * comparison with a run time, so backdated, date-only or reordered stamps (#169, #313) cannot
     * hide a document.
     *
     * A URL listed by several feeds counts once, with its newest stamp. The result is sorted oldest
     * first (ties by URL), so a budget cut takes the documents that have waited longest; whatever
     * is cut off stays pending by construction.
     *
     * @param list<FeedEntry>           $entries
     * @param array<string, HandledRow> $handled keyed by URL
     *
     * @return array{pending: list<FeedEntry>, givenUp: list<FeedEntry>}
     */
    public static function pending(array $entries, array $handled, int $maxAttempts): array
    {
        $newest = [];
        foreach ($entries as $entry) {
            $known = $newest[$entry['url']] ?? null;
            if (null === $known || $entry['updated'] > $known['updated']) {
                $newest[$entry['url']] = $entry;
            }
        }

        $pending = [];
        $givenUp = [];
        foreach ($newest as $entry) {
            $record = $handled[$entry['url']] ?? null;
            if (null === $record || $entry['updated'] > $record['feedUpdated']) {
                $pending[] = $entry;
            } elseif (CsafDocumentOutcome::Failed === $record['outcome']) {
                if ($record['attempts'] < $maxAttempts) {
                    $pending[] = $entry;
                } else {
                    $givenUp[] = $entry;
                }
            }
        }

        $oldestFirst = static fn (array $a, array $b): int => [$a['updated'], $a['url']] <=> [$b['updated'], $b['url']];
        usort($pending, $oldestFirst);
        usort($givenUp, $oldestFirst);

        return ['pending' => $pending, 'givenUp' => $givenUp];
    }

    /**
     * Up to when the content of a document has already been read — a download whose last
     * substantive change is not newer than this is rebuild-only (#98). Null = no reference, the
     * document is always processed.
     *
     * - A handled record: its stored content change, else its feed stamp (bootstrap records were
     *   never downloaded; the feed stamp is the version the old regime read).
     * - A failed record: only the content change of an earlier success — the failed attempt itself
     *   read nothing.
     * - In any case at least $floor: the later of the retention horizon and the provider's
     *   ledgerSince. Content older than that was read under the old watermark or lies before the
     *   window every provider starts with — exactly what the watermark-based check assumed.
     *
     * @param HandledRow|null $record
     */
    public static function seenContentUpTo(?array $record, ?\DateTimeImmutable $floor): ?\DateTimeImmutable
    {
        $fromRecord = null;
        if (null !== $record) {
            $fromRecord = CsafDocumentOutcome::Failed === $record['outcome']
                ? $record['contentChangedAt']
                : ($record['contentChangedAt'] ?? $record['feedUpdated']);
        }

        if (null === $fromRecord || null === $floor) {
            return $fromRecord ?? $floor;
        }

        return max($fromRecord, $floor);
    }
}
