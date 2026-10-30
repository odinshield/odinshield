<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * SEC-015 (#184): when User::isEqualTo() ends a session because the account was deactivated,
 * leave the same error the UserChecker raises at login in the session, so the login page says
 * why instead of silently asking for credentials again.
 */
#[AsEventListener]
final readonly class DeactivatedUserListener
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(TokenDeauthenticatedEvent $event): void
    {
        $user = $event->getOriginalToken()->getUser();
        $request = $event->getRequest();

        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }

        if (true === $this->userRepository->find($user->getId())?->isActive()) {
            return;
        }

        $request->getSession()->set(
            SecurityRequestAttributes::AUTHENTICATION_ERROR,
            new CustomUserMessageAccountStatusException('security.account_disabled'),
        );
    }
}
