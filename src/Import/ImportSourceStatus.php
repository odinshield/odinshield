<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Import;

use App\Enum\CsafProviderStatus;

/**
 * One row of the combined import-status view (issue #43).
 *
 * CSAF (multi-provider) and the exploitation enricher (single process) persist the same
 * operational facts in differently typed entities — `CsafProviderState` with a three-valued status
 * enum and N rows, `ExploitationEnrichmentState` with a `paused` bool and exactly one row. This DTO
 * is the common shape both are normalised into so the template renders a single table.
 *
 * The status is deliberately typed as {@see CsafProviderStatus}: its cases (enabled / auto-paused /
 * disabled) are the richer, generic superset, and the bool maps cleanly onto the first two. That
 * keeps one badge palette and one translation block (`csaf_provider_status.*`) instead of a parallel
 * enum that would duplicate `label()`/`color()` for no behavioural gain. If a third source ever needs
 * a state CSAF does not have, promote the enum to a source-neutral name then.
 */
final readonly class ImportSourceStatus
{
    /**
     * @param string   $sourceType             `csaf` or `exploitation` — drives the source label only
     * @param string   $key                    stable identifier (CSAF provider key, or `default` for the enricher)
     * @param int|null $advisoriesImportedTotal null when a source creates nothing (the enricher);
     *                                          the template renders a placeholder for null
     * @param ImportLedgerFigures|null $ledger  read volume from the CSAF document ledger (#319); null
     *                                          for the enricher, which has no documents
     * @param bool     $readsNothing            enabled, the feeds listed recent entries, nothing was
     *                                          handled — the health rule of #319
     */
    public function __construct(
        public string $sourceType,
        public string $key,
        public string $displayName,
        public CsafProviderStatus $status,
        public ?\DateTimeImmutable $lastFetchedAt,
        public ?\DateTimeImmutable $lastSuccessAt,
        public ?\DateTimeImmutable $lastErrorAt,
        public ?string $lastErrorMessage,
        public int $consecutiveFailures,
        public ?int $advisoriesImportedTotal,
        public ?ImportLedgerFigures $ledger = null,
        public bool $readsNothing = false,
    ) {
    }
}
