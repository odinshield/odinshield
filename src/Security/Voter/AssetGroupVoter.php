<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Security\Voter;

use App\Entity\AssetGroup;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, AssetGroup|null>
 */
final class AssetGroupVoter extends Voter
{
    public const string VIEW = 'ASSET_GROUP_VIEW';
    public const string MODIFY = 'ASSET_GROUP_MODIFY';
    public const string CREATE = 'ASSET_GROUP_CREATE';

    /** Software-Owner reads AssetGroups to understand what their software runs on. */
    private const array VIEW_BYPASS = [User::ROLE_ISB, User::ROLE_HARDWARE_OWNER, User::ROLE_SOFTWARE_OWNER];
    private const array MODIFY_BYPASS = [User::ROLE_ISB, User::ROLE_HARDWARE_OWNER];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, [self::VIEW, self::MODIFY, self::CREATE], true)) {
            return false;
        }

        return null === $subject || $subject instanceof AssetGroup;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $bypass = match ($attribute) {
            self::VIEW => self::VIEW_BYPASS,
            self::MODIFY, self::CREATE => self::MODIFY_BYPASS,
            default => [],
        };

        foreach ($bypass as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        if (!$subject instanceof AssetGroup) {
            return false;
        }

        // CREATE always requires a bypass role — no per-entity fallback for new entries.
        if (self::CREATE === $attribute) {
            return false;
        }

        return $subject->getResponsibleUser() === $user;
    }
}
