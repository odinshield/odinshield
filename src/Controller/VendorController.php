<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\Vendor;
use App\Form\VendorType;
use App\Repository\VendorRepository;
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

#[IsGranted(new Expression(VendorController::ACCESS))]
#[Route('/vendor')]
final class VendorController extends AbstractController
{
    /**
     * Who may open these pages at all. A constant so the audit timeline can decide on the same
     * rule whether to link an object here (#201).
     */
    public const string ACCESS = "is_granted('ROLE_SOFTWARE_OWNER') or is_granted('ROLE_ISB')";

    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_SORTS = ['name', 'vendorKey', 'website', 'advisoryUrl'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];
    private const array ALLOWED_FILTERS = ['all', 'active', 'inactive'];

    #[Route(name: 'app_vendor_index', methods: ['GET'])]
    public function index(Request $request, VendorRepository $vendorRepository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];
        $filter = $params['filter'];

        $paginator = $vendorRepository->findPaginated($search, $page, $limit, $sort, $direction, $filter);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        return $this->render('vendor/index.html.twig', [
            'vendors' => $paginator,
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

    #[Route('/new', name: 'app_vendor_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        $vendor = new Vendor();
        $form = $this->createForm(VendorType::class, $vendor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($vendor);
            $entityManager->flush();

            $this->addFlash('success', 'vendor.created');

            return $this->redirectToRoute('app_vendor_index', $returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->render('vendor/new.html.twig', [
            'vendor' => $vendor,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_vendor_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function modal(#[MapEntity(mapping: ['uuid' => 'uuid'])] Vendor $vendor): Response
    {
        return $this->render('vendor/_detail.html.twig', [
            'vendor' => $vendor,
        ]);
    }

    #[Route('/{uuid}', name: 'app_vendor_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Vendor $vendor): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('vendor/show.html.twig', [
            'vendor' => $vendor,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_vendor_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Vendor $vendor,
        EntityManagerInterface $entityManager,
        OptimisticLockConflictResolver $conflictResolver,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
        $returnToShow = 'show' === $request->query->getString('return_to');

        $form = $this->createForm(VendorType::class, $vendor);
        $form->handleRequest($request);

        $conflictInfo = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $vendor->setLockVersion((int) $form->get('lockVersion')->getData());

            try {
                $entityManager->flush();

                $this->addFlash('success', 'vendor.updated');

                if ($returnToShow) {
                    return $this->redirectToRoute('app_vendor_show', ['uuid' => $vendor->getUuid()] + $returnParams, Response::HTTP_SEE_OTHER);
                }

                return $this->redirectToRoute('app_vendor_index', $returnParams, Response::HTTP_SEE_OTHER);
            } catch (OptimisticLockException) {
                $conflictInfo = $conflictResolver->resolve($vendor);
                /** @var Vendor $vendor */
                $vendor = $conflictInfo['fresh'];
                $form = $this->createForm(VendorType::class, $vendor);
            }
        }

        return $this->render('vendor/edit.html.twig', [
            'vendor' => $vendor,
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
