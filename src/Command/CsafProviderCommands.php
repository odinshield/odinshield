<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\CsafProviderState;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafDocumentRecordRepository;
use App\Repository\CsafProviderStateRepository;
use App\Import\ImportStatusProvider;
use App\Service\Csaf\Ledger\DocumentLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CSAF provider lifecycle commands, grouped into one class so the shared
 * repository + entity manager are wired once (Symfony 8.1 method-based commands).
 */
final class CsafProviderCommands
{
    public function __construct(
        private readonly CsafProviderStateRepository $stateRepository,
        private readonly CsafDocumentRecordRepository $recordRepository,
        private readonly EntityManagerInterface $em,
        private readonly ImportStatusProvider $importStatusProvider,
    ) {
    }

    #[AsCommand(
        name: 'odinshield:csaf:provider-add',
        description: 'Register an additional CSAF trusted provider by its provider-metadata.json URL.',
    )]
    public function add(
        SymfonyStyle $io,
        #[Argument(description: 'Slug for the provider, e.g. "siemens" or "redhat" (max 64 chars).')]
        string $key,
        #[Argument(description: 'Display name, e.g. "Siemens ProductCERT".')]
        string $name,
        #[Argument(name: 'metadata-url', description: 'URL to the provider-metadata.json.')]
        string $metadataUrl,
    ): int {
        if (\strlen($key) > 64) {
            $io->error('Provider key must be ≤ 64 chars.');

            return Command::FAILURE;
        }
        if (false === filter_var($metadataUrl, \FILTER_VALIDATE_URL)) {
            $io->error('metadata-url is not a valid URL.');

            return Command::FAILURE;
        }

        if (null !== $this->stateRepository->find($key)) {
            $io->warning(\sprintf('Provider "%s" already exists.', $key));

            return Command::SUCCESS;
        }

        $state = new CsafProviderState($key, $name, $metadataUrl);
        $this->em->persist($state);
        $this->em->flush();

        $io->success(\sprintf('Provider "%s" registered. It will be picked up on the next CSAF run.', $key));

        return Command::SUCCESS;
    }

    #[AsCommand(
        name: 'odinshield:csaf:provider-list',
        description: 'List all registered CSAF providers with their current status.',
    )]
    public function listProviders(SymfonyStyle $io): int
    {
        $providers = $this->stateRepository->findAllOrdered();

        if ([] === $providers) {
            $io->warning('No CSAF providers registered.');

            return Command::SUCCESS;
        }

        // #319: the read volume from the document ledger — the same rows and rule as /import-status.
        $rows = [];
        $readsNothing = false;
        foreach ($this->importStatusProvider->all() as $source) {
            if (ImportStatusProvider::SOURCE_CSAF !== $source->sourceType || null === $source->ledger) {
                continue;
            }
            $readsNothing = $readsNothing || $source->readsNothing;
            $rows[] = [
                $source->key,
                $source->displayName,
                $source->status->label().($source->readsNothing ? ' (reads nothing!)' : ''),
                $source->lastFetchedAt?->format('Y-m-d H:i') ?? '-',
                $source->consecutiveFailures,
                $source->advisoriesImportedTotal,
                $source->ledger->recentFeedEntries ?? '-',
                $source->ledger->handledRecently,
                $source->ledger->pending ?? '-',
                $source->ledger->failing,
                $source->ledger->givenUp,
            ];
        }

        $days = DocumentLedger::RECENT_DAYS;
        $io->table(
            ['Key', 'Name', 'Status', 'Last run', 'Failures', 'Imported', \sprintf('Listed %dd', $days), \sprintf('Read %dd', $days), 'Pending', 'Failing', 'Given up'],
            $rows,
        );
        if ($readsNothing) {
            $io->warning(\sprintf('A provider marked "reads nothing" listed feed entries in the last %d days but the import read none of them — check the worker log for that provider.', $days));
        }

        return Command::SUCCESS;
    }

    #[AsCommand(
        name: 'odinshield:csaf:provider-toggle',
        description: 'Enable or disable a CSAF provider (admin active flag).',
    )]
    public function toggle(
        SymfonyStyle $io,
        #[Argument(description: 'enable or disable.')]
        string $action,
        #[Argument(description: 'Provider key.')]
        string $provider,
    ): int {
        if (!\in_array($action, ['enable', 'disable'], true)) {
            $io->error('Action must be "enable" or "disable".');

            return Command::FAILURE;
        }

        $state = $this->stateRepository->find($provider);
        if (null === $state) {
            $io->error(\sprintf('Provider "%s" not found.', $provider));

            return Command::FAILURE;
        }

        $state->setStatus('enable' === $action ? CsafProviderStatus::Enabled : CsafProviderStatus::Disabled);
        $this->em->flush();

        $io->success(\sprintf('Provider "%s" %sd.', $provider, $action));

        return Command::SUCCESS;
    }

    #[AsCommand(
        name: 'odinshield:csaf:resume',
        description: 'Resume a paused CSAF provider (clear paused flag, reset failure counter).',
    )]
    public function resume(
        SymfonyStyle $io,
        #[Argument(description: 'Provider key (e.g. bsi_wid).')]
        string $provider,
    ): int {
        $state = $this->stateRepository->find($provider);
        if (null === $state) {
            $io->error(\sprintf('Provider "%s" not found.', $provider));

            return Command::FAILURE;
        }

        if (CsafProviderStatus::Disabled === $state->getStatus()) {
            $io->error(\sprintf('Provider "%s" is disabled — use `provider-toggle enable` instead.', $provider));

            return Command::FAILURE;
        }

        $state->setStatus(CsafProviderStatus::Enabled);
        $state->setConsecutiveFailures(0);
        $state->setLastErrorMessage(null);
        $this->em->flush();

        $io->success(\sprintf('Provider "%s" resumed.', $provider));

        return Command::SUCCESS;
    }

    /**
     * An advisory is only ever matched against the inventory of the run that reads it, so one read
     * before the matching software was added is never matched again on its own. Rewinding forgets
     * the provider's ledger records of the last N days (#319): those documents are pending again
     * and the next runs re-read them.
     *
     * A provider without a ledger yet (no run since the #319 update) still has only its old
     * watermark, which the first ledger run trusts for its bootstrap. For it the watermark is moved
     * back instead — never forward — so the bootstrap does not record the range as already read.
     * This is the path for the one-off BSI catch-up after #313.
     */
    #[AsCommand(
        name: 'odinshield:csaf:rewind',
        description: 'Make a CSAF provider\'s documents of the last N days pending again, so the next run re-reads them.',
    )]
    public function rewind(
        SymfonyStyle $io,
        #[Argument(description: 'Provider key (e.g. bsi_wid).')]
        string $provider,
        #[Option(description: 'Re-read documents whose feed stamp lies within this many days before now.')]
        int $days = 90,
    ): int {
        if ($days < 1) {
            $io->error('--days must be at least 1.');

            return Command::FAILURE;
        }

        $state = $this->stateRepository->find($provider);
        if (null === $state) {
            $io->error(\sprintf('Provider "%s" not found.', $provider));

            return Command::FAILURE;
        }

        $target = new \DateTimeImmutable(\sprintf('-%d days', $days));
        $ledgerSince = $state->getLedgerSince();

        if (null === $ledgerSince) {
            $current = $state->getLastFetchedAt();
            if (null !== $current && $current <= $target) {
                $io->note(\sprintf('Provider "%s" has not run on the document ledger yet and its watermark %s is not later than the requested %s — left unchanged.', $provider, $current->format('Y-m-d H:i'), $target->format('Y-m-d H:i')));

                return Command::SUCCESS;
            }

            $state->setLastFetchedAt($target);
            $this->em->flush();
            $io->success(\sprintf('Provider "%s" rewound: its first ledger run treats only documents stamped before %s as already read.', $provider, $target->modify('-24 hours')->format('Y-m-d H:i')));
        } else {
            $forgotten = $this->recordRepository->forgetSince($provider, $target);
            // The rebuild check (#98) treats content older than ledgerSince as read; it must not
            // filter away the range that is to be re-read.
            if ($ledgerSince > $target) {
                $state->setLedgerSince($target);
            }
            $this->em->flush();
            $io->success(\sprintf('Provider "%s" rewound: %d documents stamped since %s are pending again.', $provider, $forgotten, $target->format('Y-m-d H:i')));
        }

        $io->text([
            'A document re-read at the same version changes nothing; a new match creates an entry and sends the assignment mail.',
            'A large range is spread over several runs by the per-run document budget (app.csaf.max_documents_per_provider_run).',
        ]);

        return Command::SUCCESS;
    }
}
