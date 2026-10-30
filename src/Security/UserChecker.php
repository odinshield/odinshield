<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (true !== $user->isActive()) {
            // SEC-008: translation key (not a raw English sentence) so the
            // login template can localise the message via the `security` domain.
            throw new CustomUserMessageAccountStatusException('security.account_disabled');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        // SEC-015 (audit M8, #184): runs only when a user authenticates (form login, remember-me),
        // never for a running session. A session is ended by User::isEqualTo() when the reloaded
        // account is inactive; DeactivatedUserListener then leaves this same message for the login page.
        if (true !== $user->isActive()) {
            throw new CustomUserMessageAccountStatusException('security.account_disabled');
        }
    }
}
