<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MustChangePasswordSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_change_password',
        'app_login',
        'app_logout',
        'app_manual',
        'app_manual_page',
        // SEC-MD02: Pagefind static index is gated alongside the manual itself.
        'app_pagefind_asset',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->isMustChangePassword()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/_profiler') || str_starts_with($path, '/_wdt')) {
            return;
        }

        // #188: no flash here. The change-password page explains itself and shows no warnings,
        // so each blocked click only queued another message nobody would ever see.
        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_change_password'),
        ));
    }
}
