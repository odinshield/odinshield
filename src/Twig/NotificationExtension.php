<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Twig;

use App\Entity\User;
use App\Repository\VulnerabilityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_new_vulnerability_count', $this->getNewVulnerabilityCount(...)),
        ];
    }

    public function getNewVulnerabilityCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $this->vulnerabilityRepository->countNewAssignedTo($user)
            : 0;
    }
}
