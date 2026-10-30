<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Repository\UpdateCheckStateRepository;
use App\Service\UpdateCheck\UpdateChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'odinshield:update:check',
    description: 'Check the published version.json for a newer OdinShield release now (#223), or show the last result with --status.',
)]
final class UpdateCheckCommand
{
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly UpdateCheckStateRepository $stateRepository,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only show the stored result, do not fetch.')]
        bool $status = false,
    ): int {
        if (!$this->checker->isEnabled()) {
            $io->note('The update check is switched off (UPDATE_CHECK_ENABLED=0).');

            return Command::SUCCESS;
        }

        $ok = $status || $this->checker->run();

        $state = $this->stateRepository->findState();
        $update = $this->checker->availableUpdate();
        $io->definitionList(
            ['Source' => $this->checker->getUrl()],
            ['Running version' => $this->checker->getCurrentVersion()],
            ['Published version' => $state?->getLatestVersion() ?? '—'],
            ['Update available' => null === $update ? 'no' : ($update->security ? 'yes (security)' : 'yes')],
            ['Last success' => $state?->getLastSuccessAt()?->format('Y-m-d H:i:s') ?? '—'],
            ['Last error' => null === $state?->getLastErrorAt() ? '—' : $state->getLastErrorAt()->format('Y-m-d H:i:s').' '.$state->getLastErrorMessage()],
        );

        if (!$ok) {
            $io->error('version.json could not be read — see "Last error".');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
