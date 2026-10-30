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
use App\Enum\Theme;
use App\Repository\UserRepository;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[Auditable]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'user.email.already_used')]
#[UniqueEntity(fields: ['username'], message: 'user.username.already_used')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, HasIdInterface
{
    use HasUuid;
    use HasTimestamps;
    use HasEditedBy;

    // We can use constants for roles to find usages all over the application rather
    // than doing a full-text search on the "ROLE_" string.
    // It also prevents from making typo errors.
    final public const string ROLE_USER = 'ROLE_USER';
    final public const string ROLE_ADMIN = 'ROLE_ADMIN';
    final public const string ROLE_SOFTWARE_OWNER = 'ROLE_SOFTWARE_OWNER';
    final public const string ROLE_HARDWARE_OWNER = 'ROLE_HARDWARE_OWNER';
    final public const string ROLE_ISB = 'ROLE_ISB';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[ORM\Version]
    #[Ignore]
    private int $lockVersion = 1;

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'user.email.not_blank'),
        new Assert\Email(message: 'user.email.invalid'),
        new Assert\Length(max: 180, maxMessage: 'user.email.too_long'),
    ])]
    #[ORM\Column(length: 180)]
    private string $email;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[Ignore]
    #[ORM\Column]
    private string $password;

    #[Assert\Length(max: 100, maxMessage: 'user.first_name.too_long')]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[Assert\Length(max: 100, maxMessage: 'user.last_name.too_long')]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[Ignore]
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[Assert\Length(max: 50, maxMessage: 'user.username.too_long')]
    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $username = null;

    #[Assert\Sequentially([
        new Assert\Length(max: 10, maxMessage: 'user.locale.too_long'),
        new Assert\Locale(message: 'user.locale.invalid', canonicalize: true),
    ])]
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(enumType: Theme::class, nullable: true)]
    private ?Theme $theme = null;

    // Audit L7: DB defaults align with the PHP defaults (Doctrine always inserts the PHP value,
    // so this only fixes the declared schema — no runtime behaviour change).
    #[ORM\Column(options: ['default' => true])]
    private bool $mustChangePassword = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $emailNotifications = false;

    /**
     * @var Collection<int, UserGroup>
     */
    #[ORM\ManyToMany(targetEntity: UserGroup::class, mappedBy: 'users')]
    private Collection $userGroups;

    public function __construct()
    {
        $this->initUuid();
        $this->userGroups = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        if (null === $this->username || '' === $this->username) {
            throw new \LogicException('User username must not be empty.');
        }

        return $this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    /**
     * SEC-015 (#184): decides whether a running session survives the reload of its user on each
     * request. Implementing this replaces Symfony's built-in comparison, so it repeats that one
     * (password, roles, identifier) and adds the account status, which Symfony does not look at.
     * `$this` is the session copy, whose password is the CRC32C digest from __serialize().
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        if ($this->password !== $user->getPassword() && hash('crc32c', $user->getPassword()) !== $this->password) {
            return false;
        }

        $roles = $this->getRoles();
        $refreshedRoles = $user->getRoles();
        sort($roles);
        sort($refreshedRoles);

        return $roles === $refreshedRoles
            && $this->getUserIdentifier() === $user->getUserIdentifier()
            && $user->isActive();
    }

    public function getAvatar(): string
    {
        $first = null !== $this->firstName ? mb_strtoupper(mb_substr($this->firstName, 0, 1)) : '';
        $last = null !== $this->lastName ? mb_strtoupper(mb_substr($this->lastName, 0, 1)) : '';

        if ('' === $first && '' === $last) {
            return mb_strtoupper(mb_substr($this->username ?? $this->email, 0, 1));
        }

        return $first.$last;
    }

    public function getFullName(): ?string
    {
        if (null === $this->firstName && null === $this->lastName) {
            return null;
        }

        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * SEC-018: the single choke point for username normalisation (#140). Usernames are stored
     * lowercase so that the binary unique index actually prevents duplicates — Postgres compares
     * varchar byte-wise, so 'CaseTest' and 'casetest' would otherwise be two separate accounts
     * that look identical in every list. Normalising here rather than in each write path keeps
     * the form, odinshield:user:add and odinshield:user:seed consistent for free. Lookups during
     * authentication are case-insensitive as well, see UserRepository::loadUserByIdentifier().
     */
    public function setUsername(?string $username): static
    {
        if (null !== $username) {
            $username = mb_strtolower(trim($username));
        }

        $this->username = $username;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTheme(): ?Theme
    {
        return $this->theme;
    }

    public function setTheme(?Theme $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function isMustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;

        return $this;
    }

    public function isEmailNotifications(): bool
    {
        return $this->emailNotifications;
    }

    public function setEmailNotifications(bool $emailNotifications): static
    {
        $this->emailNotifications = $emailNotifications;

        return $this;
    }

    /**
     * @return Collection<int, UserGroup>
     */
    public function getUserGroups(): Collection
    {
        return $this->userGroups;
    }

    public function addUserGroup(UserGroup $userGroup): static
    {
        if (!$this->userGroups->contains($userGroup)) {
            $this->userGroups->add($userGroup);
            $userGroup->addUser($this);
        }

        return $this;
    }

    public function removeUserGroup(UserGroup $userGroup): static
    {
        if ($this->userGroups->removeElement($userGroup)) {
            $userGroup->removeUser($this);
        }

        return $this;
    }
}
