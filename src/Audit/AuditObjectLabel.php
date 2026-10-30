<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

/**
 * Human-readable identity of one audited object, resolved from its class + database id
 * (issue #64). `deleted` marks a row whose object no longer exists — the audit trail
 * outlives the entity, so the timeline must stay readable without it.
 */
final readonly class AuditObjectLabel
{
    public function __construct(
        public string $label,
        public ?string $uuid = null,
        public ?string $route = null,
        public bool $deleted = false,
    ) {
    }

    /**
     * Fallback for an object that is gone: keep the raw id visible so the entry can still be
     * traced in the database, and mark it as deleted.
     */
    public static function deleted(int|string $id): self
    {
        return new self('#'.$id, deleted: true);
    }

    public function isLinkable(): bool
    {
        return null !== $this->uuid && null !== $this->route;
    }
}
