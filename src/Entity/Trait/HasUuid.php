<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fügt jeder Entity eine automatisch generierte UUID als zusätzlichen,
 * öffentlich lesbaren unique identifier hinzu.
 *
 * Verwendung:
 *   use HasUuid;
 *
 * Im Konstruktor der Entity aufrufen:
 *   $this->initUuid();
 *
 * Hinweis: Doctrine ORM 3+ unterstützt #[ORM\Column]-Attribute in Traits.
 * Voraussetzung: symfony/uid + symfony/doctrine-bridge (registriert den 'uuid'-DBAL-Typ).
 */
trait HasUuid
{
    #[ORM\Column(type: 'uuid', unique: true)]
    public private(set) Uuid $uuid;

    /**
     * Erzeugt eine neue UUIDv7 (time-ordered, optimal für Datenbank-Indizes).
     * Muss im Konstruktor der verwendenden Entity aufgerufen werden.
     */
    private function initUuid(): void
    {
        $this->uuid = Uuid::v7();
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }
}
