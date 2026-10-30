<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Import\ImportStatusProvider;
use App\Service\Csaf\Ledger\DocumentLedger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Combined operational status of the advisory importers (issue #43).
 *
 * ROLE_ADMIN only — a deliberate role decoupling. This view previously lived on the dashboard and
 * was visible to ROLE_ISB as well; running the importers (provider reachability, circuit breaker,
 * resume) is an administration concern, not an ISB one. The ISB keeps the vulnerability picture,
 * the admin keeps the plumbing. Do not widen this gate without revisiting that split.
 */
// SEC-007: Explicit class-level gate — defence-in-depth against future access_control refactoring,
// and it covers any route added to this controller later.
#[IsGranted('ROLE_ADMIN')]
final class ImportStatusController extends AbstractController
{
    public function __construct(
        private readonly ImportStatusProvider $importStatusProvider,
    ) {
    }

    #[Route('/import-status', name: 'app_import_status', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('import_status/index.html.twig', [
            'sources' => $this->importStatusProvider->all(),
            'recent_days' => DocumentLedger::RECENT_DAYS,
        ]);
    }
}
