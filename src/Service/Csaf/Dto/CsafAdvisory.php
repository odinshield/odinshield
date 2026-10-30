<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Dto;

final readonly class CsafAdvisory
{
    /**
     * @param array<string, CsafProduct>       $productsById
     * @param list<CsafVulnerability>          $vulnerabilities
     * @param list<array{title: string, text: string}> $documentNotes per-document notes — BSI puts
     *        the operator-relevant summary/description here, not on the per-vulnerability entries
     */
    public function __construct(
        public string $providerKey,
        public string $providerDisplayName,
        public string $documentId,
        public string $version,
        public string $title,
        public \DateTimeImmutable $initialReleaseDate,
        public \DateTimeImmutable $currentReleaseDate,
        public array $productsById,
        public array $vulnerabilities,
        public ?string $sourceUrl = null,
        /** Raw `document.aggregate_severity.text` value (e.g. "hoch", "high") if present. */
        public ?string $aggregateSeverityLabel = null,
        public array $documentNotes = [],
        /**
         * Date of the last *substantive* change, where the document lets that be told apart from
         * `$currentReleaseDate` — some providers bump the latter on every rebuild of their
         * document store without changing anything (#98). `null` means the document draws no such
         * distinction and `$currentReleaseDate` is the best available answer.
         */
        public ?\DateTimeImmutable $lastContentChangeAt = null,
    ) {
    }
}
