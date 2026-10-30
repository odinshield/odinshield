<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Entity;

use App\Entity\Trait\HasTimestamps;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafProviderStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CsafProviderStateRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'csaf_provider_state')]
class CsafProviderState
{
    use HasTimestamps;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $providerKey;

    #[ORM\Column(length: 200)]
    private string $displayName;

    #[ORM\Column(length: 500)]
    private string $providerMetadataUrl;

    /** Start of the last successful run. A read boundary until the document ledger (#319), now display only. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /**
     * Since when the document ledger is authoritative for this provider (issue #319); null until
     * the first ledger run has bootstrapped it.
     *
     * The bootstrap records every feed entry older than the old watermark minus 24 hours as handled
     * without downloading it, and stores that instant here. Content that changed before it was read
     * under the old watermark regime, which the rebuild check (#98) relies on for documents without
     * a record of their own.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ledgerSince = null;

    /**
     * How many entries the feeds listed with a stamp in the last DocumentLedger::RECENT_DAYS days,
     * as of the last successful run (#319). Only the run sees the feed; /import-status compares it
     * with what the ledger handled in the same days — listed but nothing handled is the silent
     * loss of #313, made visible.
     */
    #[ORM\Column(nullable: true)]
    private ?int $recentFeedEntries = null;

    /** Documents still pending after the last successful run: deferred by the budget, or failed and retried next run (#319). */
    #[ORM\Column(nullable: true)]
    private ?int $pendingDocuments = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastErrorAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: 'string', length: 16, enumType: CsafProviderStatus::class, options: ['default' => 'enabled'])]
    private CsafProviderStatus $status = CsafProviderStatus::Enabled;

    /**
     * When the operator was last reminded that this provider is still paused (issue #63).
     *
     * Without it the circuit breaker notifies exactly once — at the moment it trips — and then
     * never again, because findRunnable() excludes paused providers from every later run. On Demo
     * that left provider ABB dead for 74 days after a single mail nobody acted on.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPauseReminderAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $advisoriesImportedTotal = 0;

    /**
     * Feed URLs that are currently failing, mapped to the ISO-8601 instant of the FIRST failure in
     * the ongoing streak (issue #46). A feed that succeeds again is removed, so an entry always
     * describes an unbroken run of failures.
     *
     * Display only since the document ledger (#319): a failing feed contributes no entries, so its
     * documents simply stay pending — there is no window to hold back any more. The first-failure
     * instant tells /import-status how long the feed has been down.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $failingFeeds = [];

    public function __construct(string $providerKey, string $displayName, string $providerMetadataUrl)
    {
        $this->providerKey = $providerKey;
        $this->displayName = $displayName;
        $this->providerMetadataUrl = $providerMetadataUrl;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getProviderMetadataUrl(): string
    {
        return $this->providerMetadataUrl;
    }

    public function setProviderMetadataUrl(string $providerMetadataUrl): static
    {
        $this->providerMetadataUrl = $providerMetadataUrl;

        return $this;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): static
    {
        $this->lastFetchedAt = $lastFetchedAt;

        return $this;
    }

    public function getLedgerSince(): ?\DateTimeImmutable
    {
        return $this->ledgerSince;
    }

    public function setLedgerSince(?\DateTimeImmutable $ledgerSince): static
    {
        $this->ledgerSince = $ledgerSince;

        return $this;
    }

    public function getRecentFeedEntries(): ?int
    {
        return $this->recentFeedEntries;
    }

    public function setRecentFeedEntries(?int $recentFeedEntries): static
    {
        $this->recentFeedEntries = $recentFeedEntries;

        return $this;
    }

    public function getPendingDocuments(): ?int
    {
        return $this->pendingDocuments;
    }

    public function setPendingDocuments(?int $pendingDocuments): static
    {
        $this->pendingDocuments = $pendingDocuments;

        return $this;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function setLastSuccessAt(?\DateTimeImmutable $lastSuccessAt): static
    {
        $this->lastSuccessAt = $lastSuccessAt;

        return $this;
    }

    public function getLastErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    public function setLastErrorAt(?\DateTimeImmutable $lastErrorAt): static
    {
        $this->lastErrorAt = $lastErrorAt;

        return $this;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): static
    {
        $this->lastErrorMessage = $lastErrorMessage;

        return $this;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): static
    {
        $this->consecutiveFailures = $consecutiveFailures;

        return $this;
    }

    public function incrementConsecutiveFailures(): static
    {
        ++$this->consecutiveFailures;

        return $this;
    }

    public function getStatus(): CsafProviderStatus
    {
        return $this->status;
    }

    public function setStatus(CsafProviderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isRunnable(): bool
    {
        return $this->status->isRunnable();
    }

    public function getAdvisoriesImportedTotal(): int
    {
        return $this->advisoriesImportedTotal;
    }

    public function setAdvisoriesImportedTotal(int $advisoriesImportedTotal): static
    {
        $this->advisoriesImportedTotal = $advisoriesImportedTotal;

        return $this;
    }

    public function addImportedAdvisories(int $count): static
    {
        $this->advisoriesImportedTotal += $count;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getFailingFeeds(): array
    {
        return $this->failingFeeds;
    }

    /**
     * @param array<string, string> $failingFeeds
     */
    public function setFailingFeeds(array $failingFeeds): static
    {
        $this->failingFeeds = $failingFeeds;

        return $this;
    }

    /**
     * Reconcile the failing-feed map against one run's outcome (issue #46).
     *
     * Feeds that failed keep their existing first-failure instant — only newly failing ones are
     * stamped with $now. Everything else is dropped: a feed that recovered, and a feed that no
     * longer appears in the provider metadata at all, must not linger on /import-status.
     *
     * @param list<string> $failedFeedUrls feeds that threw during this run
     */
    public function recordFeedOutcome(array $failedFeedUrls, \DateTimeImmutable $now): static
    {
        $updated = [];
        foreach ($failedFeedUrls as $url) {
            $updated[$url] = $this->failingFeeds[$url] ?? $now->format(\DATE_ATOM);
        }

        $this->failingFeeds = $updated;

        return $this;
    }

    public function getLastPauseReminderAt(): ?\DateTimeImmutable
    {
        return $this->lastPauseReminderAt;
    }

    public function setLastPauseReminderAt(?\DateTimeImmutable $lastPauseReminderAt): static
    {
        $this->lastPauseReminderAt = $lastPauseReminderAt;

        return $this;
    }
}
