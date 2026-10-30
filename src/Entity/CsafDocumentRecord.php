<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Entity;

use App\Enum\CsafDocumentOutcome;
use App\Repository\CsafDocumentRecordRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One document of one provider's feed and how the import last handled it (issue #319).
 *
 * The ledger replaces the single per-provider watermark: a feed entry is pending when it has no
 * record, when the feed now shows a newer `updated` stamp than the record, or when the last
 * attempt failed. Backdated, reordered or date-only stamps (#169, #313) therefore no longer lose
 * anything — only "was this URL handled at this stamp" counts.
 *
 * Records whose feed stamp lies beyond the retention horizon are pruned, so the table stays
 * bounded at roughly one horizon's worth of feed entries per provider.
 */
#[ORM\Entity(repositoryClass: CsafDocumentRecordRepository::class)]
#[ORM\Table(name: 'csaf_document_record')]
#[ORM\UniqueConstraint(name: 'uniq_csaf_document_record_provider_url', columns: ['provider_key', 'url'])]
#[ORM\Index(name: 'idx_csaf_document_record_provider_feed_updated', columns: ['provider_key', 'feed_updated'])]
#[ORM\Index(name: 'idx_csaf_document_record_provider_handled_at', columns: ['provider_key', 'handled_at'])]
class CsafDocumentRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 64)]
    private string $providerKey;

    #[ORM\Column(length: 1000)]
    private string $url;

    /** The `updated` stamp the feed showed when the document was last handled. */
    #[ORM\Column]
    private \DateTimeImmutable $feedUpdated;

    #[ORM\Column]
    private \DateTimeImmutable $handledAt;

    #[ORM\Column(type: 'string', length: 16, enumType: CsafDocumentOutcome::class)]
    private CsafDocumentOutcome $outcome;

    /** Consecutive failed attempts at the current feed stamp; 0 after any success. */
    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $docId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $docVersion = null;

    /**
     * The document's last content change as of the last successful download, for the rebuild
     * check (#98): a later download whose content change is not newer is rebuild-only. Null for
     * records the bootstrap created without a download — the runner falls back to the old
     * watermark for those.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contentChangedAt = null;

    private function __construct(string $providerKey, string $url, \DateTimeImmutable $feedUpdated, \DateTimeImmutable $handledAt, CsafDocumentOutcome $outcome)
    {
        $this->providerKey = $providerKey;
        $this->url = $url;
        $this->feedUpdated = self::utc($feedUpdated);
        $this->handledAt = self::utc($handledAt);
        $this->outcome = $outcome;
    }

    /**
     * A document handled without error. Also used by the bootstrap, which records documents read
     * under the old watermark without downloading them (no doc id, no content change).
     */
    public static function handled(
        string $providerKey,
        string $url,
        \DateTimeImmutable $feedUpdated,
        \DateTimeImmutable $handledAt,
        CsafDocumentOutcome $outcome,
        ?string $docId = null,
        ?string $docVersion = null,
        ?\DateTimeImmutable $contentChangedAt = null,
    ): self {
        $record = new self($providerKey, $url, $feedUpdated, $handledAt, $outcome);
        $record->recordHandled($feedUpdated, $handledAt, $outcome, $docId, $docVersion, $contentChangedAt);

        return $record;
    }

    public static function failed(string $providerKey, string $url, \DateTimeImmutable $feedUpdated, \DateTimeImmutable $handledAt, string $error): self
    {
        $record = new self($providerKey, $url, $feedUpdated, $handledAt, CsafDocumentOutcome::Failed);
        $record->recordFailure($feedUpdated, $handledAt, $error);

        return $record;
    }

    public function recordHandled(
        \DateTimeImmutable $feedUpdated,
        \DateTimeImmutable $handledAt,
        CsafDocumentOutcome $outcome,
        ?string $docId = null,
        ?string $docVersion = null,
        ?\DateTimeImmutable $contentChangedAt = null,
    ): void {
        if (CsafDocumentOutcome::Failed === $outcome) {
            throw new \InvalidArgumentException(\sprintf('Use recordFailure() for outcome "%s".', $outcome->value));
        }

        $this->feedUpdated = self::utc($feedUpdated);
        $this->handledAt = self::utc($handledAt);
        $this->outcome = $outcome;
        $this->attempts = 0;
        $this->lastError = null;
        $this->docId = $docId ?? $this->docId;
        $this->docVersion = $docVersion ?? $this->docVersion;
        $this->contentChangedAt = null !== $contentChangedAt ? self::utc($contentChangedAt) : $this->contentChangedAt;
    }

    /**
     * Counts attempts per feed stamp: a document the provider has republished since the last
     * failure starts again at one, so a fixed document is not given up for its broken predecessor.
     * The content-change stamp of the last success is kept for the rebuild check.
     */
    public function recordFailure(\DateTimeImmutable $feedUpdated, \DateTimeImmutable $handledAt, string $error): void
    {
        $sameVersion = CsafDocumentOutcome::Failed === $this->outcome && $feedUpdated <= $this->feedUpdated;

        $this->attempts = $sameVersion ? $this->attempts + 1 : 1;
        $this->feedUpdated = self::utc($feedUpdated);
        $this->handledAt = self::utc($handledAt);
        $this->outcome = CsafDocumentOutcome::Failed;
        $this->lastError = $error;
    }

    public function isGivenUp(int $maxAttempts): bool
    {
        return CsafDocumentOutcome::Failed === $this->outcome && $this->attempts >= $maxAttempts;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFeedUpdated(): \DateTimeImmutable
    {
        return $this->feedUpdated;
    }

    public function getHandledAt(): \DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getOutcome(): CsafDocumentOutcome
    {
        return $this->outcome;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getDocId(): ?string
    {
        return $this->docId;
    }

    public function getDocVersion(): ?string
    {
        return $this->docVersion;
    }

    public function getContentChangedAt(): ?\DateTimeImmutable
    {
        return $this->contentChangedAt;
    }

    /**
     * The columns carry no time zone and are read back as UTC (`date.timezone`). Feed stamps come
     * with the provider's offset (`+02:00` and the like); stored unconverted, the wall-clock time
     * would be read back as UTC and the pending comparison would be off by hours.
     */
    private static function utc(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'));
    }
}
