<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\AssetGroup;
use App\Entity\User;
use App\Form\AssetGroupType;
use App\Repository\AssetGroupRepository;
use App\Repository\AssetTypeRepository;
use App\Security\Voter\AssetGroupVoter;
use App\Service\OptimisticLockConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-016 (#186): remember-me is honoured here; only sensitive actions demand a fresh login.
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route('/asset-group')]
final class AssetGroupController extends AbstractController
{
    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_SORTS = ['name', 'assetType', 'active', 'criticality', 'exposure', 'dataSensitivity'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];
    private const array ALLOWED_STATUSES = ['all', 'active', 'inactive'];
    private const array ALLOWED_ASSIGNEES = ['all', 'unassigned'];

    #[Route(name: 'app_asset_group_index', methods: ['GET'])]
    public function index(Request $request, AssetGroupRepository $repository, AssetTypeRepository $assetTypeRepository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];
        $filter = $params['filter'];
        $status = $params['status'];
        $assignee = $params['assignee'];

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        $scopedUser = ($currentUser instanceof User && !$this->isGranted(AssetGroupVoter::VIEW)) ? $currentUser : null;

        $paginator = $repository->findPaginated($search, $page, $limit, $sort, $direction, $filter, $status, $scopedUser, 'unassigned' === $assignee);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        $allAssetTypes = $assetTypeRepository->findBy([], ['name' => 'ASC']);
        $filterAssetType = 'all' !== $filter ? $assetTypeRepository->findOneBy(['uuid' => $filter]) : null;

        return $this->render('asset_group/index.html.twig', [
            'asset_groups' => $paginator,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'sort' => $sort,
            'direction' => $direction,
            'filter' => $filter,
            'status' => $status,
            'assignee' => $assignee,
            'from' => $total > 0 ? ($page - 1) * $limit + 1 : 0,
            'to' => min($page * $limit, $total),
            'all_asset_types' => $allAssetTypes,
            'filter_asset_type' => $filterAssetType,
        ]);
    }

    #[Route('/new', name: 'app_asset_group_new', methods: ['GET', 'POST'])]
    #[IsGranted(AssetGroupVoter::CREATE)]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        $assetGroup = new AssetGroup();
        $form = $this->createForm(AssetGroupType::class, $assetGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($assetGroup);
            $entityManager->flush();

            $this->addFlash('success', 'asset_group.created');

            return $this->redirectToRoute('app_asset_group_index', $returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->render('asset_group/new.html.twig', [
            'asset_group' => $assetGroup,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_asset_group_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(AssetGroupVoter::VIEW, subject: 'assetGroup')]
    public function modal(#[MapEntity(mapping: ['uuid' => 'uuid'])] AssetGroup $assetGroup): Response
    {
        return $this->render('asset_group/_detail.html.twig', [
            'asset_group' => $assetGroup,
        ]);
    }

    #[Route('/{uuid}', name: 'app_asset_group_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    #[IsGranted(AssetGroupVoter::VIEW, subject: 'assetGroup')]
    public function show(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AssetGroup $assetGroup): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('asset_group/show.html.twig', [
            'asset_group' => $assetGroup,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_asset_group_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    #[IsGranted(AssetGroupVoter::MODIFY, subject: 'assetGroup')]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AssetGroup $assetGroup,
        EntityManagerInterface $entityManager,
        OptimisticLockConflictResolver $conflictResolver,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
        $returnToShow = 'show' === $request->query->getString('return_to');

        $form = $this->createForm(AssetGroupType::class, $assetGroup);
        $form->handleRequest($request);

        $conflictInfo = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $assetGroup->setLockVersion((int) $form->get('lockVersion')->getData());

            try {
                $entityManager->flush();

                $this->addFlash('success', 'asset_group.updated');

                // An edit can drop one's own view access (e.g. handing over responsibility).
                if ($returnToShow && $this->isGranted(AssetGroupVoter::VIEW, $assetGroup)) {
                    return $this->redirectToRoute('app_asset_group_show', ['uuid' => $assetGroup->getUuid()] + $returnParams, Response::HTTP_SEE_OTHER);
                }

                return $this->redirectToRoute('app_asset_group_index', $returnParams, Response::HTTP_SEE_OTHER);
            } catch (OptimisticLockException) {
                $conflictInfo = $conflictResolver->resolve($assetGroup);
                /** @var AssetGroup $assetGroup */
                $assetGroup = $conflictInfo['fresh'];
                $form = $this->createForm(AssetGroupType::class, $assetGroup);
            }
        }

        return $this->render('asset_group/edit.html.twig', [
            'asset_group' => $assetGroup,
            'form' => $form,
            'return_params' => $returnParams,
            'return_to_show' => $returnToShow,
            'conflict' => null !== $conflictInfo,
            'lastEditor' => $conflictInfo['updatedBy'] ?? null,
            'lastEditedAt' => $conflictInfo['updatedAt'] ?? null,
        ], null !== $conflictInfo
            ? new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY)
            : null);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{search: string, limit: int, sort: string, direction: string, page: int, filter: string, status: string, assignee: string}
     */
    private function resolveListParams(array $input): array
    {
        $search = (string) ($input['search'] ?? '');
        $limit = (int) ($input['limit'] ?? 10);
        $sort = (string) ($input['sort'] ?? 'name');
        $direction = (string) ($input['direction'] ?? 'asc');
        $page = max(1, (int) ($input['page'] ?? 1));
        $filter = (string) ($input['filter'] ?? 'all');
        $status = (string) ($input['status'] ?? 'all');
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

        if ('all' !== $filter && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $filter)) {
            $filter = 'all';
        }

        if (!\in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'all';
        }

        // #66: explicit value, never an implicit one — an unknown or empty `assignee` falls back
        // to 'all' instead of accidentally producing the "unassigned" view.
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
            'status' => $status,
            'assignee' => $assignee,
        ];
    }
}
