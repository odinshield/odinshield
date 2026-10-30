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
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use App\Repository\AssetGroupRepository;
use App\Validator\Constraints\ResponsibleUserAligned;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[Auditable]
#[ORM\Entity(repositoryClass: AssetGroupRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'asset_group.name.already_used')]
#[ResponsibleUserAligned]
class AssetGroup implements HasIdInterface
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

    #[Assert\NotBlank(message: 'asset_group.name.not_blank')]
    #[Assert\Length(max: 150, maxMessage: 'asset_group.name.too_long')]
    #[ORM\Column(length: 150, unique: true)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(enumType: AssetCriticality::class, options: ['default' => 'medium'])]
    private AssetCriticality $criticality = AssetCriticality::Medium;

    #[ORM\Column(enumType: AssetExposure::class, options: ['default' => 'intranet'])]
    private AssetExposure $exposure = AssetExposure::Intranet;

    #[ORM\Column(enumType: DataSensitivity::class, options: ['default' => 'internal'])]
    private DataSensitivity $dataSensitivity = DataSensitivity::Internal;

    #[ORM\ManyToOne(targetEntity: AssetType::class, inversedBy: 'assetGroups')]
    #[ORM\JoinColumn(name: 'technology_category_id', nullable: false, onDelete: 'RESTRICT')]
    private AssetType $assetType;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $responsibleUser = null;

    /**
     * @var Collection<int, Software>
     */
    #[ORM\ManyToMany(targetEntity: Software::class, inversedBy: 'assetGroups')]
    #[ORM\JoinTable(name: 'asset_group_software')]
    private Collection $software;

    /**
     * @var Collection<int, VulnerabilityOccurrence>
     */
    #[ORM\OneToMany(mappedBy: 'assetGroup', targetEntity: VulnerabilityOccurrence::class)]
    private Collection $vulnerabilityOccurrences;

    public function __construct()
    {
        $this->initUuid();
        $this->software = new ArrayCollection();
        $this->vulnerabilityOccurrences = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getCriticality(): AssetCriticality
    {
        return $this->criticality;
    }

    public function setCriticality(AssetCriticality $criticality): static
    {
        $this->criticality = $criticality;

        return $this;
    }

    public function getExposure(): AssetExposure
    {
        return $this->exposure;
    }

    public function setExposure(AssetExposure $exposure): static
    {
        $this->exposure = $exposure;

        return $this;
    }

    public function getDataSensitivity(): DataSensitivity
    {
        return $this->dataSensitivity;
    }

    public function setDataSensitivity(DataSensitivity $dataSensitivity): static
    {
        $this->dataSensitivity = $dataSensitivity;

        return $this;
    }

    public function getAssetType(): AssetType
    {
        return $this->assetType;
    }

    public function setAssetType(AssetType $assetType): static
    {
        $this->assetType = $assetType;

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
     * @return Collection<int, Software>
     */
    public function getSoftware(): Collection
    {
        return $this->software;
    }

    /**
     * Issue #51: keeps the inverse side in sync. This is the owning side, so persistence
     * worked without it — but `Software::getAssetGroups()` stayed stale in the same request,
     * and that collection now decides which asset groups a vulnerability may name
     * (`Vulnerability::getCandidateAssetGroups()`). Both add/remove guard with `contains()`,
     * so the mutual calls settle after one hop.
     */
    public function addSoftware(Software $software): static
    {
        if (!$this->software->contains($software)) {
            $this->software->add($software);
            $software->addAssetGroup($this);
        }

        return $this;
    }

    public function removeSoftware(Software $software): static
    {
        if ($this->software->removeElement($software)) {
            $software->removeAssetGroup($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, VulnerabilityOccurrence>
     */
    public function getVulnerabilityOccurrences(): Collection
    {
        return $this->vulnerabilityOccurrences;
    }

    /**
     * Derived: distinct vulnerabilities affecting this asset group, via occurrences.
     *
     * @return Collection<int, Vulnerability>
     */
    public function getVulnerabilities(): Collection
    {
        $vulns = new ArrayCollection();
        foreach ($this->vulnerabilityOccurrences as $occurrence) {
            $vuln = $occurrence->getVulnerability();
            if (!$vulns->contains($vuln)) {
                $vulns->add($vuln);
            }
        }

        return $vulns;
    }
}
