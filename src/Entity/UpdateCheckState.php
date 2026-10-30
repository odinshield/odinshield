<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Entity;

use App\Repository\UpdateCheckStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row state of the daily update check (#223): what the published version.json said last
 * time, and which version the administrators were already mailed about. A row in the database,
 * not a cache entry — the worker writes it, the php container reads it, and the two do not share
 * a filesystem cache.
 */
#[ORM\Entity(repositoryClass: UpdateCheckStateRepository::class)]
#[ORM\Table(name: 'update_check_state')]
class UpdateCheckState
{
    public const string ID = 'default';

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $id = self::ID;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastErrorAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    /** Newest released version as published, e.g. "2.3.0". */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $latestVersion = null;

    /** Release notes link from version.json; https only (validated on read). */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $latestUrl = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $latestSecurity = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $latestReleasedAt = null;

    /** The version the administrators were last mailed about — one mail per release. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $notifiedVersion = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

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

    public function getLatestVersion(): ?string
    {
        return $this->latestVersion;
    }

    public function setLatestVersion(?string $latestVersion): static
    {
        $this->latestVersion = $latestVersion;

        return $this;
    }

    public function getLatestUrl(): ?string
    {
        return $this->latestUrl;
    }

    public function setLatestUrl(?string $latestUrl): static
    {
        $this->latestUrl = $latestUrl;

        return $this;
    }

    public function isLatestSecurity(): bool
    {
        return $this->latestSecurity;
    }

    public function setLatestSecurity(bool $latestSecurity): static
    {
        $this->latestSecurity = $latestSecurity;

        return $this;
    }

    public function getLatestReleasedAt(): ?\DateTimeImmutable
    {
        return $this->latestReleasedAt;
    }

    public function setLatestReleasedAt(?\DateTimeImmutable $latestReleasedAt): static
    {
        $this->latestReleasedAt = $latestReleasedAt;

        return $this;
    }

    public function getNotifiedVersion(): ?string
    {
        return $this->notifiedVersion;
    }

    public function setNotifiedVersion(?string $notifiedVersion): static
    {
        $this->notifiedVersion = $notifiedVersion;

        return $this;
    }
}
