<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Recheck;

final readonly class RecheckRow
{
    public function __construct(
        public int $vulnerabilityId,
        public string $title,
        public string $source,
        public string $software,
        public string $inventoryVersion,
        public string $advisoryRange,
        public RecheckVerdict $verdict,
        public string $note = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function toRow(): array
    {
        return [
            (string) $this->vulnerabilityId,
            $this->title,
            $this->source,
            $this->software,
            $this->inventoryVersion,
            $this->advisoryRange,
            $this->verdict->value,
            $this->note,
        ];
    }

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return ['id', 'title', 'source', 'software', 'inventory version', 'advisory range', 'verdict', 'note'];
    }
}
