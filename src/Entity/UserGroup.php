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
use App\Repository\UserGroupRepository;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[Auditable]
#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'user_group.name.already_used')]
class UserGroup implements HasIdInterface
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

    #[Assert\NotBlank(message: 'user_group.name.not_blank')]
    #[Assert\Length(max: 120, maxMessage: 'user_group.name.too_long')]
    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'userGroups')]
    #[ORM\JoinTable(name: 'user_group_user')]
    private Collection $users;

    /**
     * @var Collection<int, AssetType>
     */
    #[ORM\ManyToMany(targetEntity: AssetType::class, inversedBy: 'userGroups')]
    #[ORM\JoinTable(name: 'user_group_asset_type')]
    private Collection $assetTypes;

    public function __construct()
    {
        $this->initUuid();
        $this->users = new ArrayCollection();
        $this->assetTypes = new ArrayCollection();
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

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        $this->users->removeElement($user);

        return $this;
    }

    /**
     * @return Collection<int, AssetType>
     */
    public function getAssetTypes(): Collection
    {
        return $this->assetTypes;
    }

    public function addAssetType(AssetType $assetType): static
    {
        if (!$this->assetTypes->contains($assetType)) {
            $this->assetTypes->add($assetType);
        }

        return $this;
    }

    public function removeAssetType(AssetType $assetType): static
    {
        $this->assetTypes->removeElement($assetType);

        return $this;
    }
}
