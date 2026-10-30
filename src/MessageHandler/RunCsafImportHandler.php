<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\MessageHandler;

use App\Message\RunCsafImportMessage;
use App\Service\Csaf\CsafImportRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunCsafImportHandler
{
    public function __construct(private CsafImportRunner $runner)
    {
    }

    public function __invoke(RunCsafImportMessage $message): void
    {
        $this->runner->runAll();
    }
}
