<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Entity;

use App\Entity\Trait\HasEditedBy;
use App\Entity\Trait\HasTimestamps;
use App\Entity\Trait\HasUuid;
use App\Repository\VendorRepository;
use App\Validator\Constraints\VendorInactiveAllowed;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[Auditable]
#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['vendorKey'], message: 'vendor.vendor_key.already_used')]
#[VendorInactiveAllowed]
class Vendor implements HasIdInterface
{
    use HasUuid;
    use HasTimestamps;
    use HasEditedBy;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[ORM\Version]
    #[Ignore]
    private int $lockVersion = 1;

    #[Assert\NotBlank(message: 'vendor.name.not_blank')]
    #[Assert\Length(max: 150, maxMessage: 'vendor.name.too_long')]
    #[ORM\Column(length: 150)]
    private string $name;

    #[Assert\NotBlank(message: 'vendor.vendor_key.not_blank')]
    #[Assert\Length(max: 150, maxMessage: 'vendor.vendor_key.too_long')]
    #[ORM\Column(length: 150, unique: true)]
    private string $vendorKey;

    /**
     * Alternative spellings of the vendor as advisories name it (#134, D7): "SICK AG" for key
     * `sick`, "Open Source" for a BSI-style pseudo vendor. Compared like `vendorKey`.
     *
     * @var list<string>|null
     */
    #[Assert\All([new Assert\Length(max: 150, maxMessage: 'vendor.aliases.too_long')])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aliases = null;

    #[Assert\Length(max: 255, maxMessage: 'vendor.website.too_long')]
    #[Assert\Url(protocols: ['http', 'https'], message: 'vendor.website.invalid')]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[Assert\Length(max: 255, maxMessage: 'vendor.advisory_url.too_long')]
    #[Assert\Url(protocols: ['http', 'https'], message: 'vendor.advisory_url.invalid')]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $advisoryUrl = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * @var Collection<int, Software>
     */
    #[ORM\OneToMany(mappedBy: 'vendor', targetEntity: Software::class)]
    private Collection $software;

    public function __construct()
    {
        $this->initUuid();
        $this->software = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function setLockVersion(int $lockVersion): static
    {
        $this->lockVersion = $lockVersion;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getVendorKey(): string
    {
        return $this->vendorKey;
    }

    public function setVendorKey(string $vendorKey): static
    {
        $this->vendorKey = $vendorKey;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAliases(): ?array
    {
        return $this->aliases;
    }

    /**
     * @param list<string>|null $aliases
     */
    public function setAliases(?array $aliases): static
    {
        $this->aliases = null === $aliases || [] === $aliases ? null : array_values($aliases);

        return $this;
    }

    /**
     * Every token an advisory vendor may be compared against: the key plus the aliases.
     *
     * @return list<string>
     */
    public function getVendorTokens(): array
    {
        return [$this->vendorKey, ...($this->aliases ?? [])];
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getAdvisoryUrl(): ?string
    {
        return $this->advisoryUrl;
    }

    public function setAdvisoryUrl(?string $advisoryUrl): static
    {
        $this->advisoryUrl = $advisoryUrl;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, Software>
     */
    public function getSoftware(): Collection
    {
        return $this->software;
    }

    public function addSoftware(Software $software): static
    {
        if (!$this->software->contains($software)) {
            $this->software->add($software);
            $software->setVendor($this);
        }

        return $this;
    }

    public function removeSoftware(Software $software): static
    {
        $this->software->removeElement($software);

        return $this;
    }
}
