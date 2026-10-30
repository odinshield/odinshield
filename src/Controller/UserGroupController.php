<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Form\UserGroupType;
use App\Repository\UserGroupRepository;
use App\Service\OptimisticLockConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-016 (audit M7): user-group management is sensitive — require fresh authentication.
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[IsGranted('ROLE_ADMIN')]
#[Route('/user-group')]
final class UserGroupController extends AbstractController
{
    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_SORTS = ['name'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];

    #[Route(name: 'app_user_group_index', methods: ['GET'])]
    public function index(Request $request, UserGroupRepository $repository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];

        $paginator = $repository->findPaginated($search, $page, $limit, $sort, $direction);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        return $this->render('user_group/index.html.twig', [
            'user_groups' => $paginator,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'sort' => $sort,
            'direction' => $direction,
            'from' => $total > 0 ? ($page - 1) * $limit + 1 : 0,
            'to' => min($page * $limit, $total),
        ]);
    }

    #[Route('/new', name: 'app_user_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        $userGroup = new UserGroup();
        $form = $this->createForm(UserGroupType::class, $userGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($userGroup);
            $entityManager->flush();

            $this->addFlash('success', 'user_group.created');

            return $this->redirectToRoute('app_user_group_index', $returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->render('user_group/new.html.twig', [
            'user_group' => $userGroup,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_user_group_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function modal(#[MapEntity(mapping: ['uuid' => 'uuid'])] UserGroup $userGroup): Response
    {
        return $this->render('user_group/_detail.html.twig', [
            'user_group' => $userGroup,
        ]);
    }

    #[Route('/{uuid}', name: 'app_user_group_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] UserGroup $userGroup): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('user_group/show.html.twig', [
            'user_group' => $userGroup,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}', name: 'app_user_group_delete', requirements: ['uuid' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] UserGroup $userGroup,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.delete_action_limiter')]
        RateLimiterFactoryInterface $deleteActionLimiter,
    ): Response {
        // SEC-009: CSRF rejection must not silently masquerade as success.
        // Mirrors the SoftwareController::markReviewed pattern.
        if (!$this->isCsrfTokenValid('delete'.$userGroup->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // SEC-006: cap destructive admin actions per user. 20/hour is well
        // above any legitimate workflow and prevents runaway scripts.
        /** @var User $user */
        $user = $this->getUser();
        $limiter = $deleteActionLimiter->create($user->getUserIdentifier());
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $entityManager->remove($userGroup);
        $entityManager->flush();

        $this->addFlash('success', 'user_group.deleted');

        $returnParams = $this->resolveListParams($request->getPayload()->all());

        return $this->redirectToRoute('app_user_group_index', $returnParams, Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/edit', name: 'app_user_group_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] UserGroup $userGroup,
        EntityManagerInterface $entityManager,
        OptimisticLockConflictResolver $conflictResolver,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
        $returnToShow = 'show' === $request->query->getString('return_to');

        $form = $this->createForm(UserGroupType::class, $userGroup);
        $form->handleRequest($request);

        $conflictInfo = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $userGroup->setLockVersion((int) $form->get('lockVersion')->getData());

            try {
                $entityManager->flush();

                $this->addFlash('success', 'user_group.updated');

                if ($returnToShow) {
                    return $this->redirectToRoute('app_user_group_show', ['uuid' => $userGroup->getUuid()] + $returnParams, Response::HTTP_SEE_OTHER);
                }

                return $this->redirectToRoute('app_user_group_index', $returnParams, Response::HTTP_SEE_OTHER);
            } catch (OptimisticLockException) {
                $conflictInfo = $conflictResolver->resolve($userGroup);
                /** @var UserGroup $userGroup */
                $userGroup = $conflictInfo['fresh'];
                $form = $this->createForm(UserGroupType::class, $userGroup);
            }
        }

        return $this->render('user_group/edit.html.twig', [
            'user_group' => $userGroup,
            'form' => $form,
            'return_params' => $returnParams,
            'return_to_show' => $returnToShow,
            'conflict' => null !== $conflictInfo,
            'lastEditor' => $conflictInfo['updatedBy'] ?? null,
            'lastEditedAt' => $conflictInfo['updatedAt'] ?? null,
        ], null !== $conflictInfo
            // SEC/UX: Turbo treats form-submission 200 responses as no-ops and leaves the
            // submit button spinning forever. 422 makes Turbo render the new HTML (same
            // convention as form validation failures).
            ? new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY)
            : null);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{search: string, limit: int, sort: string, direction: string, page: int}
     */
    private function resolveListParams(array $input): array
    {
        $search = (string) ($input['search'] ?? '');
        $limit = (int) ($input['limit'] ?? 10);
        $sort = (string) ($input['sort'] ?? 'name');
        $direction = (string) ($input['direction'] ?? 'asc');
        $page = max(1, (int) ($input['page'] ?? 1));

        if (!\in_array($limit, self::ALLOWED_LIMITS, true)) {
            $limit = 10;
        }

        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'name';
        }

        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'asc';
        }

        return [
            'search' => $search,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
        ];
    }
}
