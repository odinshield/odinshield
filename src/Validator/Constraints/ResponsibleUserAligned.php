<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ResponsibleUserAligned extends Constraint
{
    public string $inactiveMessage = 'asset_group.responsible_user.inactive';
    public string $notAlignedMessage = 'asset_group.responsible_user.not_aligned';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
