<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\User;
use App\Import\ImportStatusProvider;
use App\Repository\AssetGroupRepository;
use App\Repository\SoftwareRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\UpdateCheck\UpdateChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

// SEC-007: Explicit gate — defence-in-depth against future access_control refactoring.
// REMEMBERED, not FULLY: remember-me is honoured for everyday browsing (SEC-016, #186).
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SoftwareRepository $softwareRepository,
        private readonly AssetGroupRepository $assetGroupRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly ChartBuilderInterface $chartBuilder,
        private readonly TranslatorInterface $translator,
        private readonly ImportStatusProvider $importStatusProvider,
        private readonly UpdateChecker $updateChecker,
        #[Autowire('%app.bsi.eos_warn_days%')]
        private readonly int $eosWarnDays,
        #[Autowire('%app.bsi.review_stale_days%')]
        private readonly int $reviewStaleDays,
    ) {
    }

    #[Route('/', name: 'app_dashboard')]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        $assignee = $user instanceof User ? $user : null;

        // Bypass roles and hardware-responsible users see ALL software in the widgets (read-only for HW users).
        // Software-responsible users without a bypass role see only their own software (with links/buttons).
        $isGlobalSoftwareViewer = $this->isGranted(User::ROLE_ISB)
            || $this->isGranted(User::ROLE_SOFTWARE_OWNER)
            || $this->isGranted(User::ROLE_HARDWARE_OWNER)
            || (null !== $assignee && $this->assetGroupRepository->isResponsibleForAnyAssetGroup($assignee));

        $showSoftwareWidgets = $isGlobalSoftwareViewer
            || (null !== $assignee && $this->softwareRepository->isResponsibleForAnySoftware($assignee));

        $widgetScopedUser = $isGlobalSoftwareViewer ? null : $assignee;

        $eosApproaching = [];
        $reviewOverdue = [];

        if ($showSoftwareWidgets) {
            $eosWindow = new \DateInterval(sprintf('P%dD', $this->eosWarnDays));
            $eosApproaching = $this->softwareRepository->findEndOfSupportWithin($eosWindow, $widgetScopedUser);

            $staleWindow = new \DateInterval(sprintf('P%dD', $this->reviewStaleDays));
            $reviewOverdue = $this->softwareRepository->findStaleReview($staleWindow, responsibleUser: $widgetScopedUser);
        }

        // Issue #43: the CSAF import-status card used to live here (ISB + admin). It moved to the
        // ROLE_ADMIN-only /import-status page, combined with the enricher — importer operations are an
        // administration concern, not an ISB one. The dashboard stays the vulnerability picture.
        //
        // Issue #63 adds back a *warning*, not the card: count and duration only, no provider
        // name, no error text. The link to /import-status is shown to ROLE_ADMIN only (#299) —
        // the same gate as ImportStatusController, so the ISB never gets a link into a 403. #43 moved
        // the operations view; it did not decide that an ISB may not learn his sources are down.
        // The trigger was a real 74-day outage on Demo that stayed invisible although the
        // auto-pause mail had gone out — one mail gets lost, a dashboard warning does not.
        // Visibility is decided here, in the controller, like $showSoftwareWidgets above: the
        // template only renders what it is handed.
        $sourceHealth = $this->isGranted(User::ROLE_ISB) || $this->isGranted(User::ROLE_ADMIN)
            ? $this->importStatusProvider->health()
            : null;

        // #223: a newer published release, for ISB and admin only (decided 2026-10-30) — the
        // admin installs it, the ISB answers for running a version with known flaws. Read from
        // the stored result of the daily check; the request itself never goes out to the net.
        $availableUpdate = $this->isGranted(User::ROLE_ISB) || $this->isGranted(User::ROLE_ADMIN)
            ? $this->updateChecker->availableUpdate()
            : null;

        // #66: "what is nobody responsible for?" — the one question the tool could not answer,
        // although responsibility is its organising idea. Shown to ISB and admin only: they are
        // the roles accountable for coverage across the whole inventory, and they are the ones
        // who can act on it. A software or asset-group owner sees their own objects anyway and
        // could not fix a gap outside their scope. Counted globally, not through the access
        // filter — a gap only half-visible is the same blind spot in a smaller window.
        // #66: "what is nobody responsible for?" — the one question the tool could not answer,
        // although responsibility is its organising idea.
        //
        // ROLE_ISB only (decided 2026-09-11). The ISB owns the coverage statement for the whole
        // inventory, and the role passes every VIEW bypass, so each of the three links opens a
        // list they can actually read. That last part is not decoration: a first draft showed the
        // hint to ROLE_ADMIN as well, and a measurement found an admin without inventory roles
        // staring at "18 objects" whose three links all ended in "no records for the current
        // filters" — the #67 rule says a global figure must never link into an unexplained empty
        // list. Owners are left out deliberately: they see and assign within their own scope,
        // the organisation-wide gap is the ISB's business.
        $unassigned = null;

        if ($this->isGranted(User::ROLE_ISB)) {
            $unassigned = [
                'vulnerabilities' => $this->vulnerabilityRepository->countWithoutResponsibleUser(),
                'software' => $this->softwareRepository->countWithoutResponsibleUser(),
                'asset_groups' => $this->assetGroupRepository->countWithoutResponsibleUser(),
            ];

            // No "0 objects" left-over banner: when everything is assigned the hint is gone.
            $unassigned = array_filter($unassigned, static fn (int $count): bool => $count > 0);
            $unassigned = [] === $unassigned ? null : $unassigned + ['total' => array_sum($unassigned)];
        }

        $currentYear = (int) date('Y');
        $previousYear = $currentYear - 1;

        return $this->render('dashboard/index.html.twig', [
            'source_health' => $sourceHealth,
            'available_update' => $availableUpdate,
            'show_import_status_link' => $this->isGranted(User::ROLE_ADMIN),
            'eos_approaching_software' => $eosApproaching,
            'review_overdue_software' => $reviewOverdue,
            'show_software_widgets' => $showSoftwareWidgets,
            'eos_warn_days' => $this->eosWarnDays,
            'review_stale_days' => $this->reviewStaleDays,
            'open_total' => $this->vulnerabilityRepository->countInActiveStatuses(),
            'open_mine' => null !== $assignee ? $this->vulnerabilityRepository->countInActiveStatuses($assignee) : 0,
            'critical_total' => $this->vulnerabilityRepository->countHighSeverityInActiveStatuses(),
            'critical_mine' => null !== $assignee ? $this->vulnerabilityRepository->countHighSeverityInActiveStatuses($assignee) : 0,
            'in_progress_total' => $this->vulnerabilityRepository->countInProgress(),
            'in_progress_mine' => null !== $assignee ? $this->vulnerabilityRepository->countInProgress($assignee) : 0,
            'unassigned' => $unassigned,
            'monthly_chart' => $this->buildMonthlyChart($previousYear, $currentYear),
            'monthly_chart_current_year' => $currentYear,
            'monthly_chart_previous_year' => $previousYear,
        ]);
    }

    private function buildMonthlyChart(int $previousYear, int $currentYear): Chart
    {
        $monthly = $this->vulnerabilityRepository->countCreatedByMonth($previousYear, $currentYear);

        $labels = [];
        for ($month = 1; $month <= 12; ++$month) {
            // Localised short month name (e.g. "Jan", "Feb" / "Jän", "Feb").
            $labels[] = $this->translator->trans('dashboard.chart.month.'.$month);
        }

        return $this->chartBuilder->createChart(Chart::TYPE_BAR)
            ->setData([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => (string) $previousYear,
                        'data' => array_values($monthly['previous']),
                        'backgroundColor' => 'rgba(110, 117, 159, 0.6)',
                        'borderColor' => 'rgba(110, 117, 159, 1)',
                        'borderWidth' => 1,
                    ],
                    [
                        'label' => (string) $currentYear,
                        'data' => array_values($monthly['current']),
                        'backgroundColor' => 'rgba(32, 107, 196, 0.7)',
                        'borderColor' => 'rgba(32, 107, 196, 1)',
                        'borderWidth' => 1,
                    ],
                ],
            ])
            ->setOptions([
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    // L20: fallback axis/legend/grid colours until JS runs. The chart-theme
                    // Stimulus controller replaces them with the theme's secondary text and border
                    // colour and re-applies them on a theme switch (#207: this blue-grey measured
                    // only 3.08:1 on the light card).
                    'legend' => [
                        'position' => 'top',
                        'labels' => ['color' => 'rgba(140, 146, 172, 1)'],
                    ],
                ],
                'scales' => [
                    'x' => [
                        'ticks' => ['color' => 'rgba(140, 146, 172, 1)'],
                        'grid' => ['color' => 'rgba(140, 146, 172, 0.15)'],
                    ],
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => ['precision' => 0, 'color' => 'rgba(140, 146, 172, 1)'],
                        'grid' => ['color' => 'rgba(140, 146, 172, 0.15)'],
                    ],
                ],
            ]);
    }
}
