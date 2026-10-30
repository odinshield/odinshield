<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\AssetGroup;
use App\Entity\Software;
use App\Enum\MaintenanceSource;
use App\Entity\User;
use App\Enum\PredecessorAction;
use App\Exception\SoftwareSuccessorAlreadyExistsException;
use App\Form\SoftwareSuccessorType;
use App\Repository\AssetGroupRepository;
use App\Repository\SoftwareRepository;
use App\Security\Voter\SoftwareVoter;
use App\Service\Software\SoftwareSuccessorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-016 (#186): remember-me is honoured here; only sensitive actions demand a fresh login.
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route('/software')]
final class SoftwareController extends AbstractController
{
    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_SORTS = ['name', 'vendor', 'productKey', 'version'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];
    private const array ALLOWED_FILTERS = ['all', 'active', 'inactive'];
    // #66: explicit value; an unknown or empty `assignee` falls back to 'all'.
    private const array ALLOWED_ASSIGNEES = ['all', 'unassigned'];

    #[Route(name: 'app_software_index', methods: ['GET'])]
    public function index(Request $request, SoftwareRepository $softwareRepository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];
        $filter = $params['filter'];
        $assignee = $params['assignee'];

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        $scopedUser = ($currentUser instanceof User && !$this->isGranted(SoftwareVoter::VIEW)) ? $currentUser : null;

        $paginator = $softwareRepository->findPaginated($search, $page, $limit, $sort, $direction, $filter, $scopedUser, 'unassigned' === $assignee);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        $softwareList = iterator_to_array($paginator->getIterator());
        $softwareIds = array_values(array_map(static fn (Software $s) => $s->getId(), $softwareList));
        $vulnStats = $softwareRepository->getVulnerabilityStatsForSoftwareIds($softwareIds);

        return $this->render('software/index.html.twig', [
            'software' => $paginator,
            'vuln_stats' => $vulnStats,
            'without_asset_group' => array_flip($softwareRepository->findImportWithoutAssetGroupIds($softwareIds)),
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'sort' => $sort,
            'direction' => $direction,
            'filter' => $filter,
            'assignee' => $assignee,
            'from' => $total > 0 ? ($page - 1) * $limit + 1 : 0,
            'to' => min($page * $limit, $total),
        ]);
    }

    #[Route('/{uuid}/mark-reviewed', name: 'app_software_mark_reviewed', requirements: ['uuid' => Requirement::UUID_V7], methods: ['POST'])]
    #[IsGranted(SoftwareVoter::MODIFY, subject: 'software')]
    public function markReviewed(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software,
        EntityManagerInterface $entityManager,
        ManagerRegistry $registry,
    ): Response {
        if (!$this->isCsrfTokenValid('mark-reviewed'.$software->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // SEC-010: Referer header is user-controlled. Return route is validated against a fixed
        // whitelist of route names (never raw URLs) to prevent open redirect. The list keeps its
        // filters (#216); the params go through the same allow-lists as the list itself.
        $allowedRoutes = ['app_software_index', 'app_dashboard'];
        $returnRoute = $request->getPayload()->getString('return_route');
        $route = \in_array($returnRoute, $allowedRoutes, true) ? $returnRoute : 'app_software_index';
        $routeParams = 'app_software_index' === $route ? $this->resolveListParams($request->getPayload()->all()) : [];

        // #216: only Manual software has a review cycle — Import is reviewed by the importer, None
        // is not maintained. The button is hidden for both; a direct POST must not bump the date.
        if (MaintenanceSource::Manual !== $software->getMaintenanceSource()) {
            $this->addFlash('warning', 'software.review.not_manual');

            return $this->redirectToRoute($route, $routeParams);
        }

        // Idempotent timestamp bump: on optimistic lock conflict, reset the EM (Doctrine closes
        // it on lock failure), re-fetch the entity, and retry once. Pure timestamp update — no
        // user-visible conflict UX needed.
        $attempt = 0;
        while (true) {
            $software->setLastReviewedAt(new \DateTimeImmutable());
            try {
                $entityManager->flush();
                break;
                // @phpstan-ignore-next-line catch.neverThrown — Doctrine throws this from flush() conditionally
            } catch (OptimisticLockException $e) {
                if (++$attempt >= 2) {
                    throw $e;
                }
                $registry->resetManager();
                $entityManager = $registry->getManager();
                $fresh = $entityManager->find(Software::class, $software->getId());
                if (null === $fresh) {
                    throw $e;
                }
                $software = $fresh;
            }
        }

        $this->addFlash('success', 'software.review.marked');

        return $this->redirectToRoute($route, $routeParams);
    }

    #[Route('/new', name: 'app_software_new', methods: ['GET'])]
    #[IsGranted(SoftwareVoter::CREATE)]
    public function new(Request $request): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());
        $software = new Software();

        return $this->render('software/new.html.twig', [
            'software' => $software,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_software_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(SoftwareVoter::VIEW, subject: 'software')]
    public function modal(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software,
        SoftwareRepository $softwareRepository,
    ): Response {
        return $this->render('software/_detail.html.twig', [
            'software' => $software,
            'successors' => $softwareRepository->findBy(['predecessor' => $software], ['version' => 'ASC']),
        ]);
    }

    #[Route('/{uuid}', name: 'app_software_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(SoftwareVoter::VIEW, subject: 'software')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software,
        SoftwareRepository $softwareRepository,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('software/show.html.twig', [
            'software' => $software,
            'successors' => $softwareRepository->findBy(['predecessor' => $software], ['version' => 'ASC']),
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_software_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(SoftwareVoter::MODIFY, subject: 'software')]
    public function edit(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('software/edit.html.twig', [
            'software' => $software,
            'return_params' => $returnParams,
            // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
            'return_to_show' => 'show' === $request->query->getString('return_to'),
        ]);
    }

    #[Route('/{uuid}/successor/new', name: 'app_software_successor_new', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(SoftwareVoter::SUCCESSOR, subject: 'software')]
    public function successorNew(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software,
        AssetGroupRepository $assetGroupRepository,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        $assetGroups = $this->resolveAssetGroups($software, $assetGroupRepository);
        $form = $this->createSuccessorForm($software, $assetGroups, $returnParams);

        return $this->renderSuccessorForm($software, $assetGroups, $form, $returnParams);
    }

    #[Route('/{uuid}/successor', name: 'app_software_successor_create', requirements: ['uuid' => Requirement::UUID_V7], methods: ['POST'])]
    #[IsGranted(SoftwareVoter::SUCCESSOR, subject: 'software')]
    public function successorCreate(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Software $software,
        SoftwareSuccessorService $successorService,
        AssetGroupRepository $assetGroupRepository,
        TranslatorInterface $translator,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        $assetGroups = $this->resolveAssetGroups($software, $assetGroupRepository);
        $form = $this->createSuccessorForm($software, $assetGroups, $returnParams);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderSuccessorForm($software, $assetGroups, $form, $returnParams);
        }

        /** @var Software $draft */
        $draft = $form->getData();

        $allowedAg = [SoftwareSuccessorService::AG_MIGRATE, SoftwareSuccessorService::AG_KEEP, SoftwareSuccessorService::AG_BOTH];

        /** @var array<int, 'migrate'|'keep'|'both'> $assetGroupChoices */
        $assetGroupChoices = [];
        foreach ($assetGroups as $assetGroup) {
            $field = 'ag_choice_'.$assetGroup->getId();
            if (!$form->has($field)) {
                continue;
            }
            $value = (string) $form->get($field)->getData();
            if (\in_array($value, $allowedAg, true)) {
                $assetGroupChoices[$assetGroup->getId()] = $value;
            }
        }

        /** @var PredecessorAction $predecessorAction */
        $predecessorAction = $form->get('predecessor_action')->getData();

        try {
            $successorService->createSuccessor(
                $software,
                $draft,
                $assetGroupChoices,
                $predecessorAction,
            );
        } catch (SoftwareSuccessorAlreadyExistsException) {
            // The template prints error.message verbatim, so the message must arrive translated.
            $form->get('version')->addError(new FormError(
                $translator->trans('software.successor.error.duplicate'),
                'software.successor.error.duplicate',
            ));

            return $this->renderSuccessorForm($software, $assetGroups, $form, $returnParams);
        }

        $this->addFlash('success', 'software.successor.flash.created');

        return $this->redirectToRoute('app_software_show', ['uuid' => $software->getUuid()] + $returnParams);
    }

    /**
     * @param list<AssetGroup>                                                                                              $assetGroups
     * @param array{search: string, limit: int, sort: string, direction: string, page: int, filter: string, assignee: string} $returnParams
     *
     * @return FormInterface<Software>
     */
    private function createSuccessorForm(Software $software, array $assetGroups, array $returnParams): FormInterface
    {
        return $this->createForm(SoftwareSuccessorType::class, $this->prefillSuccessor($software), [
            'asset_groups' => $assetGroups,
            // #214: the list context rides along in the action so the POST can hand it back.
            'action' => $this->generateUrl('app_software_successor_create', ['uuid' => $software->getUuid()] + $returnParams),
        ]);
    }

    /**
     * @param list<AssetGroup>                                                                                              $assetGroups
     * @param FormInterface<Software>                                                                                       $form
     * @param array{search: string, limit: int, sort: string, direction: string, page: int, filter: string, assignee: string} $returnParams
     */
    private function renderSuccessorForm(Software $software, array $assetGroups, FormInterface $form, array $returnParams): Response
    {
        return $this->render('software/successor/new.html.twig', [
            'predecessor' => $software,
            'asset_groups' => $assetGroups,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    /**
     * Query the AGs explicitly to avoid in-memory inverse-collection traps
     * (the M:N is owned by AssetGroup; lazy-load on the inverse side is unreliable
     * if the source entity was added via the owning helper only).
     *
     * @return list<AssetGroup>
     */
    private function resolveAssetGroups(Software $software, AssetGroupRepository $repository): array
    {
        return $repository->createQueryBuilder('ag')
            ->innerJoin('ag.software', 's')
            ->where('s.id = :id')
            ->setParameter('id', $software->getId())
            ->orderBy('ag.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    private function prefillSuccessor(Software $source): Software
    {
        $draft = new Software();
        $draft->setName($source->getName());
        $draft->setProductKey($source->getProductKey());
        $draft->setVendor($source->getVendor());
        $draft->setResponsibleUser($source->getResponsibleUser());
        $draft->setMaintenanceSource($source->getMaintenanceSource());
        $draft->setActive(true);
        $draft->setVersion('');
        // Aliases identify the product, not the version — they carry over unchanged.
        $draft->setAliases($source->getAliases());

        return $draft;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{search: string, limit: int, sort: string, direction: string, page: int, filter: string, assignee: string}
     */
    private function resolveListParams(array $input): array
    {
        $search = (string) ($input['search'] ?? '');
        $limit = (int) ($input['limit'] ?? 10);
        $sort = (string) ($input['sort'] ?? 'name');
        $direction = (string) ($input['direction'] ?? 'asc');
        $page = max(1, (int) ($input['page'] ?? 1));
        $filter = (string) ($input['filter'] ?? 'all');
        $assignee = (string) ($input['assignee'] ?? 'all');

        if (!\in_array($limit, self::ALLOWED_LIMITS, true)) {
            $limit = 10;
        }

        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'name';
        }

        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'asc';
        }

        if (!\in_array($filter, self::ALLOWED_FILTERS, true)) {
            $filter = 'all';
        }

        if (!\in_array($assignee, self::ALLOWED_ASSIGNEES, true)) {
            $assignee = 'all';
        }

        return [
            'search' => $search,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'filter' => $filter,
            'assignee' => $assignee,
        ];
    }
}
