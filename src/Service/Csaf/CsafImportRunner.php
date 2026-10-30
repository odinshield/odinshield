<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf;

use App\Entity\CsafProviderState;
use App\Entity\Software;
use App\Enum\CsafProviderStatus;
use App\Enum\MaintenanceSource;
use App\Entity\CsafDocumentRecord;
use App\Enum\CsafDocumentOutcome;
use App\Repository\CsafDocumentRecordRepository;
use App\Repository\CsafProviderStateRepository;
use App\Repository\SoftwareRepository;
use App\Service\Csaf\Exception\CsafFetchException;
use App\Service\Csaf\Exception\CsafParseException;
use App\Service\Csaf\Fetcher\AggregatorExpander;
use App\Service\Csaf\Fetcher\ChangesCsvReader;
use App\Service\Csaf\Fetcher\ProviderMetadataFetcher;
use App\Service\Csaf\Fetcher\RolieFeedReader;
use App\Service\Csaf\Ledger\DocumentLedger;
use App\Service\Csaf\Parser\CsafDocumentParser;
use App\Service\Csaf\Upserter\VulnerabilityUpserter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrates one full CSAF import cycle across all active providers.
 *
 * What a run reads is decided by the document ledger (#319): every feed entry within the retention
 * horizon that has no record, a newer stamp than its record, or a failed last attempt. It replaced
 * the per-provider fetch watermark, which lost documents silently whenever a provider's stamps were
 * not precise, monotonic and published in order (#169, #313).
 *
 * @phpstan-import-type HandledRow from DocumentLedger
 *
 * @phpstan-type ProviderStats array{processed: int, imported: int, updated: int, fixAvailable: int, skipped: int, rebuildSkipped: int, failed: int, deferred: int, givenUp: int}
 */
final readonly class CsafImportRunner
{
    /** #313: BSI stamps date only and publishes later the same day — the bootstrap trusts the old watermark only up to a day before it. */
    private const string BOOTSTRAP_OVERLAP = '-24 hours';

    public function __construct(
        private CsafProviderStateRepository $stateRepository,
        private CsafDocumentRecordRepository $documentRecordRepository,
        private SoftwareRepository $softwareRepository,
        private ProviderMetadataFetcher $metadataFetcher,
        private RolieFeedReader $rolieFeedReader,
        private ChangesCsvReader $changesCsvReader,
        private CsafDocumentParser $parser,
        private VulnerabilityUpserter $upserter,
        private AggregatorExpander $aggregatorExpander,
        private AdminFailureNotifier $failureNotifier,
        private HttpClientInterface $httpClient,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
        #[Autowire('%app.csaf.bsi_provider_metadata_url%')]
        private string $bsiProviderMetadataUrl,
        #[Autowire('%app.csaf.bsi_aggregator_url%')]
        private string $bsiAggregatorUrl,
        #[Autowire('%app.csaf.ledger_retention_days%')]
        private int $ledgerRetentionDays,
        #[Autowire('%app.csaf.max_document_attempts%')]
        private int $maxDocumentAttempts,
        #[Autowire('%app.csaf.failure_threshold%')]
        private int $failureThreshold,
        #[Autowire('%app.csaf.pause_reminder_days%')]
        private int $pauseReminderDays,
        #[Autowire('%app.csaf.http_timeout_seconds%')]
        private int $httpTimeoutSeconds,
        #[Autowire('%app.csaf.batch_flush_size%')]
        private int $batchFlushSize,
        #[Autowire('%app.csaf.max_documents_per_provider_run%')]
        private int $maxDocumentsPerProviderRun,
        #[Autowire('%app.csaf.memory_limit%')]
        private string $memoryLimit = '',
    ) {
    }

    /**
     * Run a full import across every active, non-paused provider.
     *
     * @return array<string, ProviderStats>
     */
    public function runAll(): array
    {
        $this->raiseMemoryLimitIfNeeded();
        $this->ensureBsiBootstrap();

        try {
            $this->aggregatorExpander->expand($this->bsiAggregatorUrl);
        } catch (CsafFetchException|CsafParseException $e) {
            $this->logger->warning('CSAF aggregator expansion failed', ['error' => $e->getMessage()]);
        }

        $providerKeys = array_map(
            static fn (CsafProviderState $s) => $s->getProviderKey(),
            $this->stateRepository->findRunnable(),
        );

        $startedAt = microtime(true);
        $stats = [];
        $anyProviderSucceeded = false;

        foreach ($providerKeys as $key) {
            // Re-load state and software per provider on the *current* EM. If a previous
            // provider's failed flush closed the EM, handleProviderFailure() reset the manager
            // (M1) — reloading here guarantees we never iterate detached entities, so the
            // remaining providers still process.
            $state = $this->em()->find(CsafProviderState::class, $key);
            if (null === $state) {
                continue;
            }
            $allSoftware = $this->softwareRepository->findAllImportMatchable();
            $this->logUncomparableVersions($allSoftware);

            $successBefore = $state->getLastSuccessAt();
            $stats[$key] = $this->runForProvider($state, $allSoftware);
            if ($state->getLastSuccessAt() != $successBefore) {
                $anyProviderSucceeded = true;
            }
        }

        // Issue #63: remind about providers the breaker has paused. They are excluded from
        // findRunnable() and therefore never touched again by the loop above — which is exactly
        // why the one auto-pause mail used to be the last word on the subject.
        $this->remindAboutPausedProviders();

        // Only advance the review timestamp when something was actually cross-checked.
        // If every feed failed (network outage, all providers in error), the software list
        // wasn't reviewed against anything — bumping last_reviewed_at would falsely satisfy
        // the BSI-CS 093 §5.2 review-overdue check.
        if ($anyProviderSucceeded) {
            $this->markSoftwareAsCsafReviewed($this->softwareRepository->findAllImportMatchable());
        }

        // #319: keep the ledger bounded. Feeds are read back to the same horizon, so a record
        // older than it can never decide anything again.
        try {
            $pruned = $this->documentRecordRepository->pruneBefore(new \DateTimeImmutable('-'.$this->ledgerRetentionDays.' days'));
        } catch (\Throwable $e) {
            $pruned = 0;
            $this->logger->error('CSAF ledger pruning failed', ['error' => $e->getMessage()]);
        }

        // #98: one summary line per cycle. The console command prints its table, but the scheduled
        // run goes through RunCsafImportHandler, which discards the return value — without this,
        // regular operation leaves no trace of how much was processed, skipped as a rebuild, or
        // deferred by the per-provider budget. Wall time is included because "an import run stays
        // within a reasonable time frame" is only checkable if it is written down somewhere.
        $this->logger->info('CSAF import cycle finished', [
            'providers' => \count($stats),
            'processed' => array_sum(array_column($stats, 'processed')),
            'imported' => array_sum(array_column($stats, 'imported')),
            'updated' => array_sum(array_column($stats, 'updated')),
            'skipped' => array_sum(array_column($stats, 'skipped')),
            'rebuildSkipped' => array_sum(array_column($stats, 'rebuildSkipped')),
            'failed' => array_sum(array_column($stats, 'failed')),
            'deferred' => array_sum(array_column($stats, 'deferred')),
            'givenUp' => array_sum(array_column($stats, 'givenUp')),
            'ledgerPruned' => $pruned,
            'durationSeconds' => round(microtime(true) - $startedAt, 1),
        ]);

        return $stats;
    }

    /**
     * The currently-active EntityManager. Resolved fresh from the registry on every call so a
     * mid-cycle resetManager() (after a failed flush) is transparent to the rest of the runner.
     */
    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Re-notify about every provider that is still auto-paused and whose last reminder is older
     * than `app.csaf.pause_reminder_days` (issue #63).
     *
     * The interval is deliberately NOT the run interval: with `0 3,15 * * *` that would be two
     * mails per day per broken provider — new noise instead of a fix. The first reminder is due
     * one full interval after the auto-pause mail, for which `lastPauseReminderAt` is stamped
     * when the breaker trips.
     */
    private function remindAboutPausedProviders(): void
    {
        $now = new \DateTimeImmutable();
        $due = $now->modify('-'.$this->pauseReminderDays.' days');
        $reminded = false;

        foreach ($this->stateRepository->findAutoPaused() as $state) {
            $last = $state->getLastPauseReminderAt();
            // No stamp at all means the pause predates this feature — remind once, then settle
            // into the normal rhythm instead of staying quiet forever.
            if (null !== $last && $last > $due) {
                continue;
            }

            $lastSuccess = $state->getLastSuccessAt();
            try {
                $this->failureNotifier->notifyStillPaused(
                    $state,
                    null === $lastSuccess ? null : (int) $lastSuccess->diff($now)->days,
                );
            } catch (\Throwable $mailError) {
                $this->logger->error('CSAF pause-reminder dispatch failed', [
                    'providerKey' => $state->getProviderKey(),
                    'error' => $mailError->getMessage(),
                ]);
                // Deliberately no stamp on failure: an undelivered reminder must be retried, not
                // counted as done.
                continue;
            }

            $state->setLastPauseReminderAt($now);
            $reminded = true;

            $this->logger->warning('CSAF provider is still paused', [
                'providerKey' => $state->getProviderKey(),
                'lastSuccessAt' => $lastSuccess?->format('c'),
            ]);
        }

        if ($reminded) {
            try {
                $this->em()->flush();
            } catch (\Throwable $e) {
                $this->logger->error('CSAF pause-reminder bookkeeping flush failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * #134, D4: an Import-maintained product whose version has no numeric start is compared as
     * "any version" — every advisory for it is reported. The inventory shows a warning; the run
     * says it once too, so the operator who only reads logs learns about it as well.
     *
     * @param list<Software> $allSoftware
     */
    private function logUncomparableVersions(array $allSoftware): void
    {
        $names = [];
        foreach ($allSoftware as $software) {
            if (null === $software->getComparableVersion()) {
                $names[] = $software->getName().' '.$software->getVersion();
            }
        }
        if ([] !== $names) {
            $this->logger->notice('CSAF import: inventory versions without a numeric part are matched as "any version"', [
                'software' => $names,
            ]);
        }
    }

    /**
     * Bump `lastReviewedAt` for every Import-maintained software that was cross-checked during
     * this run. Running the CSAF importer is the review event for Import software: every key was
     * compared against every advisory feed we could fetch, so the BSI-CS 093 §5.2 review
     * timestamp can advance — regardless of whether the cross-check yielded a match. Manual- and
     * None-maintained software never reaches this list (findAllImportMatchable), so the importer
     * cannot satisfy a human review clock. Bypasses the audit log on purpose: one entry per
     * software per twice-daily run would drown out user-meaningful audit history.
     *
     * @param list<Software> $allSoftware
     */
    private function markSoftwareAsCsafReviewed(array $allSoftware): void
    {
        $ids = array_values(array_map(
            static fn (Software $s) => $s->getId(),
            array_filter($allSoftware, static fn (Software $s) => MaintenanceSource::Import === $s->getMaintenanceSource()),
        ));
        if ([] === $ids) {
            return;
        }
        $this->em()->getConnection()->executeStatement(
            'UPDATE software SET last_reviewed_at = :now WHERE id IN (:ids)',
            ['now' => new \DateTimeImmutable(), 'ids' => $ids],
            ['now' => Types::DATETIME_IMMUTABLE, 'ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param list<Software> $allSoftware
     *
     * @return ProviderStats
     */
    public function runForProvider(CsafProviderState $state, array $allSoftware): array
    {
        $stats = ['processed' => 0, 'imported' => 0, 'updated' => 0, 'fixAvailable' => 0, 'skipped' => 0, 'rebuildSkipped' => 0, 'failed' => 0, 'deferred' => 0, 'givenUp' => 0];

        try {
            $fetchStartedAt = new \DateTimeImmutable();

            $feeds = $this->metadataFetcher->fetch($state->getProviderMetadataUrl());
            if ([] === $feeds) {
                // A provider advertising zero readable distributions is a misconfiguration, not a
                // successful empty run — record it so it surfaces in the provider status (L13).
                // "Readable" matters since #62: feeds behind a TLP restriction are skipped, so
                // this can also mean "every feed needs credentials" rather than "none exist".
                $this->handleProviderFailure($state, new CsafFetchException('Provider metadata yielded no readable distributions — neither ROLIE feeds nor a directory (feeds behind a TLP restriction are skipped).'));

                return $stats;
            }

            // #319: feeds are read back to the retention horizon on every run; the ledger, not a
            // read boundary, decides which of those entries still need to be handled.
            $horizon = $fetchStartedAt->modify('-'.$this->ledgerRetentionDays.' days');

            $feedFailures = 0;
            // Issue #46: which feeds threw this run, and why — for /import-status. A failing feed
            // contributes no entries, so its documents simply stay pending until it recovers.
            $failedFeedUrls = [];
            $feedErrors = [];
            $entries = [];

            foreach ($feeds as $feed) {
                $feedUrl = $feed['url'];
                try {
                    // Issue #97: both readers return the identical shape, so everything below is
                    // untouched by which distribution kind we just read.
                    $feedEntries = 'directory' === $feed['type']
                        ? $this->changesCsvReader->readUpdatedSince($feedUrl, $horizon)
                        : $this->rolieFeedReader->readUpdatedSince($feedUrl, $horizon);
                } catch (CsafFetchException|CsafParseException $feedError) {
                    ++$feedFailures;
                    $failedFeedUrls[] = $feedUrl;
                    $feedErrors[] = \sprintf('%s: %s', $feedUrl, $feedError->getMessage());
                    $this->logger->warning('CSAF feed read failed (continuing with other feeds)', [
                        'providerKey' => $state->getProviderKey(),
                        'feedUrl' => $feedUrl,
                        'error' => $feedError->getMessage(),
                    ]);
                    continue;
                }
                array_push($entries, ...$feedEntries);
            }

            if ($feedFailures === \count($feeds)) {
                $this->handleProviderFailure($state, new CsafFetchException(\sprintf('All %d feeds failed.', $feedFailures)));

                return $stats;
            }

            $this->bootstrapLedger($state, $entries, $horizon);

            $handled = $this->documentRecordRepository->findHandled($state->getProviderKey());
            $selection = DocumentLedger::pending($entries, $handled, $this->maxDocumentAttempts);
            $pending = $selection['pending'];
            $stats['givenUp'] = \count($selection['givenUp']);

            // #98: a per-provider budget, so one very large provider cannot starve the others in
            // the same run — Red Hat changed 3.149 documents on 2026-09-16, Schneider about 15 a
            // day. The pending set is oldest first; what is cut off has no new record and is
            // therefore pending again next run. No group or hold rule is needed any more (#169).
            if ($this->maxDocumentsPerProviderRun > 0 && \count($pending) > $this->maxDocumentsPerProviderRun) {
                $stats['deferred'] = \count($pending) - $this->maxDocumentsPerProviderRun;
                $pending = \array_slice($pending, 0, $this->maxDocumentsPerProviderRun);
            }

            // The rebuild check's lower bound for documents without a useful record of their own.
            $ledgerSince = $state->getLedgerSince();
            $contentFloor = null !== $ledgerSince && $ledgerSince > $horizon ? $ledgerSince : $horizon;

            $handledSinceFlush = 0;
            foreach ($pending as $entry) {
                $known = $handled[$entry['url']] ?? null;
                $result = $this->processOneDocument(
                    $state,
                    $entry['url'],
                    $allSoftware,
                    DocumentLedger::seenContentUpTo($known, $contentFloor),
                );
                $this->recordOutcome($state->getProviderKey(), $entry, null !== $known, $result);

                if (null !== $result['error']) {
                    ++$stats['failed'];
                } elseif ($result['rebuildOnly']) {
                    ++$stats['rebuildSkipped'];
                } elseif (null !== $result['stats']) {
                    ++$stats['processed'];
                    $stats['imported'] += $result['stats']['imported'];
                    $stats['updated'] += $result['stats']['updated'];
                    $stats['fixAvailable'] += $result['stats']['fixAvailable'];
                    $stats['skipped'] += $result['stats']['skipped'];
                }

                ++$handledSinceFlush;
                if ($handledSinceFlush >= $this->batchFlushSize) {
                    // Persist the batch so pending changes don't accumulate unbounded in the
                    // UnitOfWork. We deliberately do NOT em.clear() — that would detach
                    // $allSoftware and its lazy AssetGroups, breaking occurrence creation for
                    // the rest of the run.
                    $this->em()->flush();
                    $handledSinceFlush = 0;
                }
            }

            if ($stats['deferred'] > 0) {
                // Not an error: the provider had more pending documents than one run may spend on
                // it. The remainder stays pending and is picked up by the next runs.
                $this->logger->info('CSAF provider hit its per-run document budget', [
                    'providerKey' => $state->getProviderKey(),
                    'budget' => $this->maxDocumentsPerProviderRun,
                    'deferred' => $stats['deferred'],
                    'rebuildSkipped' => $stats['rebuildSkipped'],
                ]);
            }
            if ($stats['givenUp'] > 0) {
                // No silent give-up: the documents stay listed until the provider republishes them
                // or they leave the retention horizon.
                $this->logger->warning('CSAF documents given up after repeated failures', [
                    'providerKey' => $state->getProviderKey(),
                    'maxAttempts' => $this->maxDocumentAttempts,
                    'urls' => array_column($selection['givenUp'], 'url'),
                ]);
            }

            // #319: what only the run can see, for the read-volume figures on /import-status.
            $recentSince = $fetchStartedAt->modify(\sprintf('-%d days', DocumentLedger::RECENT_DAYS));
            $recentUrls = [];
            foreach ($entries as $entry) {
                if ($entry['updated'] >= $recentSince) {
                    $recentUrls[$entry['url']] = true;
                }
            }
            $state->setRecentFeedEntries(\count($recentUrls));
            $state->setPendingDocuments($stats['deferred'] + $stats['failed']);

            $state->recordFeedOutcome($failedFeedUrls, $fetchStartedAt);
            $state->setLastFetchedAt($fetchStartedAt);
            $state->setLastSuccessAt(new \DateTimeImmutable());
            $state->setConsecutiveFailures(0);
            // Keep partial feed failures visible on /import-status instead of clearing them —
            // a provider that silently fetches nothing used to look perfectly healthy here.
            $state->setLastErrorMessage([] === $feedErrors ? null : \sprintf(
                '%d of %d feeds failed, their documents stay pending: %s',
                $feedFailures,
                \count($feeds),
                implode(' | ', $feedErrors),
            ));
            if ([] !== $feedErrors) {
                $state->setLastErrorAt(new \DateTimeImmutable());
            }
            $state->addImportedAdvisories($stats['imported']);
            $this->em()->flush();
        } catch (CsafFetchException|CsafParseException|HttpExceptionInterface $e) {
            $this->handleProviderFailure($state, $e);
        } catch (\Throwable $e) {
            $this->logger->error('CSAF runner unexpected error', [
                'providerKey' => $state->getProviderKey(),
                'error' => $e->getMessage(),
            ]);
            $this->handleProviderFailure($state, $e);
        }

        return $stats;
    }

    /**
     * First ledger run of a provider (#319): records every entry older than the old watermark
     * minus 24 hours as handled without downloading it — the old regime has read those. Without
     * this the first run would re-read the whole horizon (Red Hat: about 25k documents, 34 runs at
     * the budget). The 24 hours cover BSI's date-only stamps (#313). Documents the old window lost
     * stay lost; `odinshield:csaf:rewind` brings them back.
     *
     * A provider without a watermark (new, never run) has nothing to trust: everything within the
     * horizon is pending, as the initial window used to be.
     *
     * @param list<array{url: string, updated: \DateTimeImmutable}> $entries
     */
    private function bootstrapLedger(CsafProviderState $state, array $entries, \DateTimeImmutable $horizon): void
    {
        if (null !== $state->getLedgerSince()) {
            return;
        }

        $watermark = $state->getLastFetchedAt();
        if (null === $watermark) {
            $state->setLedgerSince($horizon);
            $this->em()->flush();

            return;
        }

        $cutoff = $watermark->modify(self::BOOTSTRAP_OVERLAP);
        $alreadyRead = [];
        foreach ($entries as $entry) {
            if ($entry['updated'] < $cutoff) {
                $known = $alreadyRead[$entry['url']] ?? null;
                if (null === $known || $entry['updated'] > $known['updated']) {
                    $alreadyRead[$entry['url']] = $entry;
                }
            }
        }

        // Handled "by the old watermark", not now: otherwise /import-status counts thousands of
        // documents as read this week that were never downloaded (seen in the first trial run).
        $inserted = $this->documentRecordRepository->insertHandledWithoutDownload($state->getProviderKey(), array_values($alreadyRead), $watermark);
        $state->setLedgerSince($cutoff);
        $this->em()->flush();

        $this->logger->info('CSAF ledger bootstrapped from the old watermark', [
            'providerKey' => $state->getProviderKey(),
            'watermark' => $watermark->format(\DATE_ATOM),
            'recordedWithoutDownload' => $inserted,
        ]);
    }

    /**
     * @param array{url: string, updated: \DateTimeImmutable}                                                                                                                                                       $entry
     * @param array{stats: array{imported: int, updated: int, fixAvailable: int, skipped: int}|null, error: ?string, rebuildOnly: bool, docId: ?string, docVersion: ?string, contentChangedAt: ?\DateTimeImmutable} $result
     */
    private function recordOutcome(string $providerKey, array $entry, bool $known, array $result): void
    {
        $record = $known ? $this->documentRecordRepository->findOneByDocument($providerKey, $entry['url']) : null;
        $now = new \DateTimeImmutable();

        if (null !== $result['error']) {
            if (null === $record) {
                $this->em()->persist(CsafDocumentRecord::failed($providerKey, $entry['url'], $entry['updated'], $now, $result['error']));
            } else {
                $record->recordFailure($entry['updated'], $now, $result['error']);
            }

            return;
        }

        $outcome = match (true) {
            $result['rebuildOnly'] => CsafDocumentOutcome::Rebuild,
            null === $result['stats'] => CsafDocumentOutcome::Skipped,
            default => CsafDocumentOutcome::Processed,
        };
        if (null === $record) {
            $this->em()->persist(CsafDocumentRecord::handled($providerKey, $entry['url'], $entry['updated'], $now, $outcome, $result['docId'], $result['docVersion'], $result['contentChangedAt']));
        } else {
            $record->recordHandled($entry['updated'], $now, $outcome, $result['docId'], $result['docVersion'], $result['contentChangedAt']);
        }
    }

    /**
     * Fetch, parse and upsert a single CSAF document. The result discriminates four outcomes, each
     * of which becomes the document's ledger record:
     * - `error` set           → fetch/parse error; the document stays pending and is retried.
     * - `rebuildOnly = true`  → the provider re-published the document without changing it (#98);
     *                           counted separately so the noise stays visible.
     * - `stats = null`        → intentionally skipped (e.g. a non-final document).
     * - `stats = array`       → processed; upsert statistics to accumulate.
     *
     * @param list<Software> $allSoftware
     * @param ?\DateTimeImmutable $seenContentUpTo content changed up to here was already read (DocumentLedger::seenContentUpTo())
     *
     * @return array{stats: array{imported: int, updated: int, fixAvailable: int, skipped: int}|null, error: ?string, rebuildOnly: bool, docId: ?string, docVersion: ?string, contentChangedAt: ?\DateTimeImmutable}
     */
    private function processOneDocument(CsafProviderState $state, string $documentUrl, array $allSoftware, ?\DateTimeImmutable $seenContentUpTo): array
    {
        $none = ['stats' => null, 'error' => null, 'rebuildOnly' => false, 'docId' => null, 'docVersion' => null, 'contentChangedAt' => null];

        try {
            $response = $this->httpClient->request('GET', $documentUrl, [
                'timeout' => $this->httpTimeoutSeconds,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $payload = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('CSAF document fetch failed', [
                'providerKey' => $state->getProviderKey(),
                'url' => $documentUrl,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Fetch failed: '.$e->getMessage()] + $none;
        }

        try {
            $advisory = $this->parser->parse($state->getProviderKey(), $state->getDisplayName(), $payload, $documentUrl);
        } catch (CsafParseException $e) {
            if (CsafDocumentParser::SKIP_NON_FINAL === $e->getCode()) {
                $this->logger->info('CSAF document skipped (not final)', [
                    'providerKey' => $state->getProviderKey(),
                    'url' => $documentUrl,
                ]);

                return $none;
            }
            $this->logger->warning('CSAF document parse failed', [
                'providerKey' => $state->getProviderKey(),
                'url' => $documentUrl,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Parse failed: '.$e->getMessage()] + $none;
        }

        $parsed = [
            'docId' => $advisory->documentId,
            'docVersion' => $advisory->version,
            'contentChangedAt' => $advisory->lastContentChangeAt ?? $advisory->currentReleaseDate,
        ] + $none;

        // #98: the provider re-published this document without touching its content — the
        // revision history says the last substantive change is one we have already read (#319:
        // per the ledger record, no longer per a watermark). Skipping here saves the expensive
        // part: matching against the whole inventory plus the upsert. The download itself cannot
        // be saved, because the distinction only exists inside the document.
        if (null !== $advisory->lastContentChangeAt && null !== $seenContentUpTo && $advisory->lastContentChangeAt <= $seenContentUpTo) {
            $this->logger->debug('CSAF document skipped (rebuild only, no content change)', [
                'providerKey' => $state->getProviderKey(),
                'url' => $documentUrl,
                'lastContentChangeAt' => $advisory->lastContentChangeAt->format(\DATE_ATOM),
            ]);

            return ['rebuildOnly' => true] + $parsed;
        }

        return ['stats' => $this->upserter->upsertAdvisory($advisory, $allSoftware)] + $parsed;
    }

    private function handleProviderFailure(CsafProviderState $state, \Throwable $e): void
    {
        if (!$this->em()->isOpen()) {
            // A failed flush (e.g. a DB constraint violation from a single malformed document)
            // closes the EM. Reset it and reload the detached state so the failure counter still
            // persists and runAll() can carry on with the remaining providers (M1).
            $this->registry->resetManager();
            $reloaded = $this->em()->find(CsafProviderState::class, $state->getProviderKey());
            if (null !== $reloaded) {
                $state = $reloaded;
            }
        }

        $state->setLastErrorAt(new \DateTimeImmutable());
        $state->setLastErrorMessage($e->getMessage());
        $state->incrementConsecutiveFailures();

        // Issue #63: until now this failure went ONLY into lastErrorMessage. A `csaf:run --sync`
        // therefore printed a harmless zero in its statistics table and said nothing about the
        // provider that just failed — that is how ABB's idle timeout slipped through several
        // diagnostic runs on 2026-09-10. Feed-level failures one layer down have always logged a
        // warning and were visible immediately; only the provider-level one was mute.
        // Logged at error level once the breaker trips: it travels the existing MONOLOG_ERROR_TO
        // path, so an operator is reached even where CSAF_ADMIN_EMAIL is unset.
        // #312: a failure below the threshold is a warning only. Error level on every failure mailed
        // each transient connect timeout (11 mails in six days, none above 2 of 3); the breaker and
        // /import-status already cover a provider that stays down. A warning still prints on
        // `csaf:run --sync` without -v, so #63 stays fixed.
        $tripped = $state->getConsecutiveFailures() >= $this->failureThreshold;
        $this->logger->log($tripped ? LogLevel::ERROR : LogLevel::WARNING, 'CSAF provider failed', [
            'providerKey' => $state->getProviderKey(),
            'consecutiveFailures' => $state->getConsecutiveFailures(),
            'threshold' => $this->failureThreshold,
            'error' => $e->getMessage(),
        ]);

        if ($tripped) {
            $state->setStatus(CsafProviderStatus::AutoPaused);
            // Stamp before sending so the first reminder is due one full interval later rather
            // than on the very next run.
            $state->setLastPauseReminderAt(new \DateTimeImmutable());
            try {
                $this->failureNotifier->notifyPaused($state);
            } catch (\Throwable $mailError) {
                $this->logger->error('CSAF failure-notification dispatch failed', [
                    'providerKey' => $state->getProviderKey(),
                    'error' => $mailError->getMessage(),
                ]);
            }
        }

        try {
            $this->em()->flush();
        } catch (\Throwable $flushError) {
            // Last-resort guard: even the failure bookkeeping could not be persisted. Log and
            // move on rather than aborting the whole cycle.
            $this->logger->error('CSAF failure bookkeeping flush failed', [
                'providerKey' => $state->getProviderKey(),
                'error' => $flushError->getMessage(),
            ]);
        }
    }

    /**
     * Raise PHP's memory_limit only when the configured value is larger than the current one.
     * Lets ops downgrade by passing a smaller -d memory_limit=… without us undoing their choice.
     */
    private function raiseMemoryLimitIfNeeded(): void
    {
        if ('' === $this->memoryLimit) {
            return;
        }
        $configured = $this->parseBytes($this->memoryLimit);
        $current = $this->parseBytes((string) \ini_get('memory_limit'));
        if (-1 === $current) {
            return;
        }
        if (-1 === $configured || $configured > $current) {
            \ini_set('memory_limit', $this->memoryLimit);
        }
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private function ensureBsiBootstrap(): void
    {
        $existing = $this->stateRepository->find('bsi_wid');
        if (null !== $existing) {
            return;
        }
        $state = new CsafProviderState(
            'bsi_wid',
            'BSI Warn- und Informationsdienst',
            $this->bsiProviderMetadataUrl,
        );
        $this->em()->persist($state);
        $this->em()->flush();
    }
}
