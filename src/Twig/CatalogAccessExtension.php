<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Twig;

use App\Entity\User;
use App\Repository\AssetGroupRepository;
use App\Repository\SoftwareRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CatalogAccessExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly SoftwareRepository $softwareRepository,
        private readonly AssetGroupRepository $assetGroupRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_is_software_responsible', $this->isSoftwareResponsible(...)),
            new TwigFunction('app_is_asset_group_responsible', $this->isAssetGroupResponsible(...)),
        ];
    }

    public function isSoftwareResponsible(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->softwareRepository->isResponsibleForAnySoftware($user);
    }

    public function isAssetGroupResponsible(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->assetGroupRepository->isResponsibleForAnyAssetGroup($user);
    }
}
