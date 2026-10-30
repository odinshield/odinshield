<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Import;

use App\Entity\CsafDocumentRecord;
use App\Entity\CsafProviderState;
use App\Entity\ExploitationEnrichmentState;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafDocumentRecordRepository;
use App\Repository\CsafProviderStateRepository;
use App\Repository\ExploitationEnrichmentStateRepository;
use App\Service\Csaf\Ledger\DocumentLedger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assembles the combined status of the CSAF import and the exploitation enrichment (issue #43,
 * #134).
 *
 * Read-only by contract: this runs on a GET and must never create the enricher's state row — see
 * {@see ExploitationEnrichmentStateRepository::findState()}. A source that has never run yet is
 * still listed, with null timestamps, so a silently never-scheduled process is visible rather
 * than absent.
 */
final readonly class ImportStatusProvider
{
    public const string SOURCE_CSAF = 'csaf';
    public const string SOURCE_EXPLOITATION = 'exploitation';

    /** Names of the two originals — not translated, they are proper nouns. */
    private const string EXPLOITATION_DISPLAY_NAME = 'FIRST EPSS / CISA KEV';

    /** How many given-up documents a row lists; the count above it is complete. */
    private const int GIVEN_UP_LISTED = 10;

    public function __construct(
        private CsafProviderStateRepository $csafProviderStateRepository,
        private ExploitationEnrichmentStateRepository $exploitationStateRepository,
        private CsafDocumentRecordRepository $documentRecordRepository,
        #[Autowire('%app.csaf.max_document_attempts%')]
        private int $maxDocumentAttempts,
    ) {
    }

    /**
     * @return list<ImportSourceStatus>
     */
    public function all(): array
    {
        $figures = $this->documentRecordRepository->figuresByProvider(
            new \DateTimeImmutable(\sprintf('-%d days', DocumentLedger::RECENT_DAYS)),
            $this->maxDocumentAttempts,
        );

        $rows = array_map(
            fn (CsafProviderState $state): ImportSourceStatus => $this->fromCsaf($state, $figures[$state->getProviderKey()] ?? null),
            $this->csafProviderStateRepository->findAllOrdered(),
        );

        $rows[] = $this->fromExploitation($this->exploitationStateRepository->findState());

        return $rows;
    }

    /**
     * Issue #63: the count-and-duration summary the dashboard shows an ISB. Returns null when
     * every source is healthy, so the caller renders nothing at all.
     *
     * "Disrupted" means {@see CsafProviderStatus::AutoPaused} — the circuit breaker gave up, the
     * source delivers nothing. Deliberately excluded:
     *   - `Disabled`: switched off on purpose (Huawei is access-restricted and will never deliver),
     *     so reporting it would produce a warning nobody can ever clear.
     *   - `Enabled` with failures below the threshold: the breaker is still retrying. Reporting a
     *     source that may well recover on the next run is exactly the noise #62 removed.
     *   - "Reads nothing" (#319): runs succeed but handle no document — a defect in our import, not
     *     an outage; the banner's "nothing fetched for N days" would misstate it. It shows on
     *     /import-status and in provider-list for the admin who has to repair it.
     */
    public function health(): ?ImportSourceHealth
    {
        $broken = array_values(array_filter(
            $this->all(),
            static fn (ImportSourceStatus $s) => CsafProviderStatus::AutoPaused === $s->status,
        ));
        if ([] === $broken) {
            return null;
        }

        $successes = array_filter(array_map(
            static fn (ImportSourceStatus $s) => $s->lastSuccessAt,
            $broken,
        ));

        return new ImportSourceHealth(
            disruptedCount: \count($broken),
            // The *oldest* success among the broken sources is the longest-running disruption.
            // A source that never succeeded contributes no date — it is counted, but cannot make
            // the age older than it is.
            oldestSuccessOfBroken: [] === $successes ? null : min($successes),
        );
    }

    /**
     * @param array{handledRecently: int, processedRecently: int, failing: int, givenUp: int}|null $figures null when the provider has no ledger records
     */
    private function fromCsaf(CsafProviderState $state, ?array $figures): ImportSourceStatus
    {
        $givenUp = $figures['givenUp'] ?? 0;
        $ledger = new ImportLedgerFigures(
            recentFeedEntries: $state->getRecentFeedEntries(),
            handledRecently: $figures['handledRecently'] ?? 0,
            processedRecently: $figures['processedRecently'] ?? 0,
            pending: $state->getPendingDocuments(),
            failing: $figures['failing'] ?? 0,
            givenUp: $givenUp,
            givenUpDocuments: 0 === $givenUp ? [] : array_map(
                static fn (CsafDocumentRecord $r): array => ['url' => $r->getUrl(), 'lastError' => $r->getLastError(), 'handledAt' => $r->getHandledAt()],
                $this->documentRecordRepository->findGivenUp($state->getProviderKey(), $this->maxDocumentAttempts, self::GIVEN_UP_LISTED),
            ),
        );

        return new ImportSourceStatus(
            sourceType: self::SOURCE_CSAF,
            key: $state->getProviderKey(),
            displayName: $state->getDisplayName(),
            status: $state->getStatus(),
            lastFetchedAt: $state->getLastFetchedAt(),
            lastSuccessAt: $state->getLastSuccessAt(),
            lastErrorAt: $state->getLastErrorAt(),
            lastErrorMessage: $state->getLastErrorMessage(),
            consecutiveFailures: $state->getConsecutiveFailures(),
            advisoriesImportedTotal: $state->getAdvisoriesImportedTotal(),
            ledger: $ledger,
            readsNothing: CsafProviderStatus::Enabled === $state->getStatus() && $ledger->readsNothing(),
        );
    }

    /**
     * @param ExploitationEnrichmentState|null $state null when the enricher has never run — rendered
     *                                                as an enabled source with no history, never persisted
     */
    private function fromExploitation(?ExploitationEnrichmentState $state): ImportSourceStatus
    {
        return new ImportSourceStatus(
            sourceType: self::SOURCE_EXPLOITATION,
            key: ExploitationEnrichmentState::ID,
            displayName: self::EXPLOITATION_DISPLAY_NAME,
            // `paused` is set by the circuit breaker (AutoPaused) or by odinshield:exploitation:pause;
            // the table cannot tell the two apart and does not need to — both mean "not delivering".
            status: null !== $state && $state->isPaused() ? CsafProviderStatus::AutoPaused : CsafProviderStatus::Enabled,
            lastFetchedAt: $state?->getLastRunAt(),
            lastSuccessAt: $state?->getLastSuccessAt(),
            lastErrorAt: $state?->getLastErrorAt(),
            lastErrorMessage: $state?->getLastErrorMessage(),
            consecutiveFailures: $state?->getConsecutiveFailures() ?? 0,
            // Enrichment creates nothing; the "imported" column stays a dash.
            advisoriesImportedTotal: null,
        );
    }
}
