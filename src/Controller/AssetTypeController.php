<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\AssetType;
use App\Form\AssetTypeType;
use App\Repository\AssetTypeRepository;
use App\Service\OptimisticLockConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(new Expression(AssetTypeController::ACCESS))]
#[Route('/asset-type')]
final class AssetTypeController extends AbstractController
{
    /**
     * Who may open these pages at all. A constant so the audit timeline can decide on the same
     * rule whether to link an object here (#201).
     */
    public const string ACCESS = "is_granted('ROLE_HARDWARE_OWNER') or is_granted('ROLE_ISB')";

    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_SORTS = ['name', 'active'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];
    private const array ALLOWED_FILTERS = ['all', 'active', 'inactive'];

    #[Route(name: 'app_asset_type_index', methods: ['GET'])]
    public function index(Request $request, AssetTypeRepository $repository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];
        $filter = $params['filter'];

        $paginator = $repository->findPaginated($search, $page, $limit, $sort, $direction, $filter);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        $assetTypeIds = array_map(static fn (AssetType $t): int => $t->getId(), iterator_to_array($paginator->getIterator()));
        $assetGroupCounts = $repository->countAssetGroupsByAssetTypeIds($assetTypeIds);

        return $this->render('asset_type/index.html.twig', [
            'asset_types' => $paginator,
            'asset_group_counts' => $assetGroupCounts,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'sort' => $sort,
            'direction' => $direction,
            'filter' => $filter,
            'from' => $total > 0 ? ($page - 1) * $limit + 1 : 0,
            'to' => min($page * $limit, $total),
        ]);
    }

    #[Route('/new', name: 'app_asset_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        $assetType = new AssetType();
        $form = $this->createForm(AssetTypeType::class, $assetType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($assetType);
            $entityManager->flush();

            $this->addFlash('success', 'asset_type.created');

            return $this->redirectToRoute('app_asset_type_index', $returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->render('asset_type/new.html.twig', [
            'asset_type' => $assetType,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_asset_type_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function modal(#[MapEntity(mapping: ['uuid' => 'uuid'])] AssetType $assetType): Response
    {
        return $this->render('asset_type/_detail.html.twig', [
            'asset_type' => $assetType,
        ]);
    }

    #[Route('/{uuid}', name: 'app_asset_type_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AssetType $assetType): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('asset_type/show.html.twig', [
            'asset_type' => $assetType,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_asset_type_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AssetType $assetType,
        EntityManagerInterface $entityManager,
        OptimisticLockConflictResolver $conflictResolver,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
        $returnToShow = 'show' === $request->query->getString('return_to');

        $form = $this->createForm(AssetTypeType::class, $assetType);
        $form->handleRequest($request);

        $conflictInfo = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $assetType->setLockVersion((int) $form->get('lockVersion')->getData());

            try {
                $entityManager->flush();

                $this->addFlash('success', 'asset_type.updated');

                if ($returnToShow) {
                    return $this->redirectToRoute('app_asset_type_show', ['uuid' => $assetType->getUuid()] + $returnParams, Response::HTTP_SEE_OTHER);
                }

                return $this->redirectToRoute('app_asset_type_index', $returnParams, Response::HTTP_SEE_OTHER);
            } catch (OptimisticLockException) {
                // Re-render with the OTHER user's persisted state so the current user can see
                // what changed; their submitted input is intentionally discarded.
                $conflictInfo = $conflictResolver->resolve($assetType);
                /** @var AssetType $assetType */
                $assetType = $conflictInfo['fresh'];
                $form = $this->createForm(AssetTypeType::class, $assetType);
            }
        }

        return $this->render('asset_type/edit.html.twig', [
            'asset_type' => $assetType,
            'form' => $form,
            'return_params' => $returnParams,
            'return_to_show' => $returnToShow,
            'conflict' => null !== $conflictInfo,
            'lastEditor' => $conflictInfo['updatedBy'] ?? null,
            'lastEditedAt' => $conflictInfo['updatedAt'] ?? null,
        ], null !== $conflictInfo
            // Turbo discards 200 form responses; 422 triggers a client-side render.
            ? new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY)
            : null);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{search: string, limit: int, sort: string, direction: string, page: int, filter: string}
     */
    private function resolveListParams(array $input): array
    {
        $search = (string) ($input['search'] ?? '');
        $limit = (int) ($input['limit'] ?? 10);
        $sort = (string) ($input['sort'] ?? 'name');
        $direction = (string) ($input['direction'] ?? 'asc');
        $page = max(1, (int) ($input['page'] ?? 1));
        $filter = (string) ($input['filter'] ?? 'all');

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

        return [
            'search' => $search,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'filter' => $filter,
        ];
    }
}
