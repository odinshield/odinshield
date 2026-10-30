<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\MessageHandler;

use App\Message\RunUpdateCheckMessage;
use App\Service\UpdateCheck\UpdateChecker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunUpdateCheckHandler
{
    public function __construct(private UpdateChecker $checker)
    {
    }

    public function __invoke(RunUpdateCheckMessage $message): void
    {
        $this->checker->run();
    }
}
