<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
class LocaleListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User || null === $user->getLocale()) {
            return;
        }

        $locale = $user->getLocale();

        $event->getRequest()->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
