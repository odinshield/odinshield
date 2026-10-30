<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Message\RunCsafImportMessage;
use App\Service\Csaf\CsafImportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'odinshield:csaf:run',
    description: 'Trigger a CSAF import run (dispatch async message, or --sync for direct invocation).',
)]
final class CsafRunCommand
{
    public function __construct(
        private readonly CsafImportRunner $runner,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Run the importer synchronously instead of dispatching to the queue.')]
        bool $sync = false,
    ): int {
        if (!$sync) {
            $this->bus->dispatch(new RunCsafImportMessage());
            $io->success('CSAF import dispatched to the async transport.');

            return Command::SUCCESS;
        }

        $io->title('CSAF import (synchronous)');
        $stats = $this->runner->runAll();

        if ([] === $stats) {
            $io->warning('No active providers found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($stats as $providerKey => $providerStats) {
            $rows[] = [
                $providerKey,
                $providerStats['processed'],
                $providerStats['imported'],
                $providerStats['updated'],
                $providerStats['fixAvailable'],
                $providerStats['skipped'],
                $providerStats['rebuildSkipped'],
                $providerStats['failed'],
                $providerStats['deferred'],
                $providerStats['givenUp'],
            ];
        }
        // #98: "Rebuilt" is what makes the rebuild filter observable. Without the column, a run
        // against a provider that re-publishes daily looks like it processed suspiciously few
        // documents, with nothing to say where the rest went. #319: the same for documents that
        // failed (retried next run), were deferred by the budget, or were given up.
        $io->table(['Provider', 'Processed', 'Imported', 'Updated', 'Fix noted', 'Skipped', 'Rebuilt', 'Failed', 'Deferred', 'Given up'], $rows);

        return Command::SUCCESS;
    }
}
