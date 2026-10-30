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
use App\Enum\MaintenanceSource;
use App\Repository\SoftwareRepository;
use App\Service\Csaf\Matching\ComparableVersion;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[Auditable]
#[ORM\Entity(repositoryClass: SoftwareRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(
    name: 'uniq_software_vendor_product_version',
    columns: ['vendor_id', 'product_key', 'version'],
)]
#[ORM\Index(name: 'idx_software_eol_date', columns: ['eol_date'])]
#[UniqueEntity(
    fields: ['vendor', 'productKey', 'version'],
    message: 'software.vendor_product_version.already_used',
)]
class Software implements HasIdInterface
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

    #[Assert\NotBlank(message: 'software.name.not_blank')]
    #[Assert\Length(max: 150, maxMessage: 'software.name.too_long')]
    #[ORM\Column(length: 150)]
    private string $name;

    #[Assert\NotBlank(message: 'software.product_key.not_blank')]
    #[Assert\Length(max: 150, maxMessage: 'software.product_key.too_long')]
    #[ORM\Column(length: 150)]
    private string $productKey;

    #[Assert\NotBlank(message: 'software.version.not_blank')]
    #[Assert\Length(max: 80, maxMessage: 'software.version.too_long')]
    #[ORM\Column(length: 80)]
    private string $version;

    /**
     * Alternative spellings of the product as advisories name it (#134, D7). Compared for
     * equality with the advisory's product token after normalisation, exactly like `productKey`
     * — a way to bridge "CorivaEngine" ↔ `coriva_engine` without renaming the key.
     *
     * @var list<string>|null
     */
    #[Assert\All([new Assert\Length(max: 150, maxMessage: 'software.aliases.too_long')])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aliases = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eolDate = null;

    #[ORM\Column(enumType: MaintenanceSource::class, options: ['default' => 'manual'])]
    private MaintenanceSource $maintenanceSource = MaintenanceSource::Manual;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReviewedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'software')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $responsibleUser = null;

    /**
     * @var Collection<int, AssetGroup>
     */
    #[ORM\ManyToMany(targetEntity: AssetGroup::class, mappedBy: 'software')]
    private Collection $assetGroups;

    /**
     * @var Collection<int, Vulnerability>
     */
    #[ORM\ManyToMany(targetEntity: Vulnerability::class, mappedBy: 'software')]
    private Collection $vulnerabilities;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'successors')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Software $predecessor = null;

    /**
     * @var Collection<int, Software>
     */
    #[ORM\OneToMany(mappedBy: 'predecessor', targetEntity: self::class)]
    private Collection $successors;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    public function __construct()
    {
        $this->initUuid();
        $this->assetGroups = new ArrayCollection();
        $this->vulnerabilities = new ArrayCollection();
        $this->successors = new ArrayCollection();
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

    public function getProductKey(): string
    {
        return $this->productKey;
    }

    public function setProductKey(string $productKey): static
    {
        $this->productKey = $productKey;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;

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
     * Every token an advisory product may be compared against: the key plus the aliases.
     *
     * @return list<string>
     */
    public function getProductTokens(): array
    {
        return [$this->productKey, ...($this->aliases ?? [])];
    }

    /**
     * The leading dotted-numeric part of the version, or null when the version has no numeric
     * start (#134, D4). Null means "any version": the importer reports every advisory for this
     * product and the UI shows a warning. Not persisted — derived from `version` on every call.
     */
    public function getComparableVersion(): ?string
    {
        $comparable = ComparableVersion::fromString($this->version);

        return null === $comparable ? null : (string) $comparable;
    }

    public function getEolDate(): ?\DateTimeImmutable
    {
        return $this->eolDate;
    }

    public function setEolDate(?\DateTimeImmutable $eolDate): static
    {
        $this->eolDate = $eolDate;

        return $this;
    }

    public function getMaintenanceSource(): MaintenanceSource
    {
        return $this->maintenanceSource;
    }

    public function setMaintenanceSource(MaintenanceSource $maintenanceSource): static
    {
        $this->maintenanceSource = $maintenanceSource;

        return $this;
    }

    public function getLastReviewedAt(): ?\DateTimeImmutable
    {
        return $this->lastReviewedAt;
    }

    public function setLastReviewedAt(?\DateTimeImmutable $lastReviewedAt): static
    {
        $this->lastReviewedAt = $lastReviewedAt;

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

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    /** A new Software has no vendor until the form sets one; `getVendor()` would throw. */
    public function hasVendor(): bool
    {
        return isset($this->vendor);
    }

    public function setVendor(Vendor $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getResponsibleUser(): ?User
    {
        return $this->responsibleUser;
    }

    public function setResponsibleUser(?User $responsibleUser): static
    {
        $this->responsibleUser = $responsibleUser;

        return $this;
    }

    /**
     * @return Collection<int, AssetGroup>
     */
    public function getAssetGroups(): Collection
    {
        return $this->assetGroups;
    }

    public function addAssetGroup(AssetGroup $assetGroup): static
    {
        if (!$this->assetGroups->contains($assetGroup)) {
            $this->assetGroups->add($assetGroup);
            $assetGroup->addSoftware($this);
        }

        return $this;
    }

    public function removeAssetGroup(AssetGroup $assetGroup): static
    {
        if ($this->assetGroups->removeElement($assetGroup)) {
            $assetGroup->removeSoftware($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Vulnerability>
     */
    public function getVulnerabilities(): Collection
    {
        return $this->vulnerabilities;
    }

    public function addVulnerability(Vulnerability $vulnerability): static
    {
        if (!$this->vulnerabilities->contains($vulnerability)) {
            $this->vulnerabilities->add($vulnerability);
            $vulnerability->addSoftware($this);
        }

        return $this;
    }

    public function removeVulnerability(Vulnerability $vulnerability): static
    {
        if ($this->vulnerabilities->removeElement($vulnerability)) {
            $vulnerability->removeSoftware($this);
        }

        return $this;
    }

    public function getPredecessor(): ?self
    {
        return $this->predecessor;
    }

    public function setPredecessor(?self $predecessor): static
    {
        $this->predecessor = $predecessor;

        return $this;
    }

    /**
     * @return Collection<int, Software>
     */
    public function getSuccessors(): Collection
    {
        return $this->successors;
    }

    public function getSupersededAt(): ?\DateTimeImmutable
    {
        return $this->supersededAt;
    }

    public function setSupersededAt(?\DateTimeImmutable $supersededAt): static
    {
        $this->supersededAt = $supersededAt;

        return $this;
    }
}
