<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\AssetGroupRepository;
use App\Repository\SoftwareRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\MailNotifier;
use App\Service\OptimisticLockConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-016 (audit M7): user management is sensitive — require fresh authentication, not just a
// remember-me cookie, on top of the admin role.
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[IsGranted('ROLE_ADMIN')]
#[Route('/user')]
final class UserController extends AbstractController
{
    private const array ALLOWED_LIMITS = [10, 25, 50, 75, 100];
    private const array ALLOWED_FILTERS = ['all', 'active', 'inactive'];
    private const array ALLOWED_SORTS = ['username', 'email', 'firstName', 'lastName', 'isActive', 'lastLoginAt'];
    private const array ALLOWED_DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(name: 'app_user_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $params = $this->resolveListParams($request->query->all());
        $search = $params['search'];
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $direction = $params['direction'];
        $filter = $params['filter'];

        $isActive = match ($filter) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        $paginator = $userRepository->findPaginated($search, $page, $limit, $sort, $direction, $isActive);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $limit));

        return $this->render('user/index.html.twig', [
            'users' => $paginator,
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

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['require_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'user.created');

            return $this->redirectToRoute('app_user_index', $returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/modal', name: 'app_user_modal', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function modal(#[MapEntity(mapping: ['uuid' => 'uuid'])] User $user): Response
    {
        return $this->render('user/_detail.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{uuid}', name: 'app_user_show', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $user): Response
    {
        $returnParams = $this->resolveListParams($request->query->all());

        return $this->render('user/show.html.twig', [
            'user' => $user,
            'return_params' => $returnParams,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_user_edit', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] User $user,
        EntityManagerInterface $entityManager,
        SoftwareRepository $softwareRepository,
        AssetGroupRepository $assetGroupRepository,
        VulnerabilityRepository $vulnerabilityRepository,
        OptimisticLockConflictResolver $conflictResolver,
        TranslatorInterface $translator,
        MailNotifier $mailNotifier,
    ): Response {
        $returnParams = $this->resolveListParams($request->query->all());
        // #211: only the fixed value 'show' is honoured — no caller-supplied redirect target.
        $returnToShow = 'show' === $request->query->getString('return_to');
        $wasActive = $user->isActive();
        // #269: read before handleRequest() writes the submitted address onto the entity.
        $previousEmail = $user->getEmail();

        $form = $this->createForm(UserType::class, $user, ['require_password' => false]);
        $form->handleRequest($request);

        $conflictInfo = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setLockVersion((int) $form->get('lockVersion')->getData());

            $currentUser = $this->getUser();
            $isSelfLockout = $currentUser instanceof User
                && $currentUser->getId() === $user->getId()
                && (!$user->isActive() || !\in_array(User::ROLE_ADMIN, $user->getRoles(), true));

            if ($isSelfLockout) {
                // SEC-015 (audit M8): an admin must not deactivate or de-admin their own account
                // — that would lock them (and possibly the last admin) out. Reject, don't persist.
                // The form template prints error.message verbatim, so it must arrive translated.
                $form->addError(new FormError($translator->trans('user.self_lockout_blocked'), 'user.self_lockout_blocked'));
            } else {
                /** @var string|null $plainPassword */
                $plainPassword = $form->get('plainPassword')->getData();
                if (null !== $plainPassword && '' !== $plainPassword) {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                }

                if ($wasActive && !$user->isActive()) {
                    $softwareCount = $softwareRepository->countResponsibleSoftware($user);
                    $assetGroupCount = $assetGroupRepository->countResponsibleAssetGroups($user);
                    $vulnerabilityCount = $vulnerabilityRepository->countInActiveStatuses($user);

                    if ($softwareCount > 0 || $assetGroupCount > 0 || $vulnerabilityCount > 0) {
                        $this->addFlash('warning', 'user.deactivated_with_responsibilities');
                    }
                }

                try {
                    $entityManager->flush();

                    // #269: an address changed by an admin is reported to the old address too.
                    // A password set here is an admin reset, not a voluntary change — no notice.
                    if ($previousEmail !== $user->getEmail()) {
                        $mailNotifier->notifyEmailChanged($user, $previousEmail, byAdmin: $user !== $currentUser);
                    }

                    $this->addFlash('success', 'user.updated');

                    if ($returnToShow) {
                        return $this->redirectToRoute('app_user_show', ['uuid' => $user->getUuid()] + $returnParams, Response::HTTP_SEE_OTHER);
                    }

                    return $this->redirectToRoute('app_user_index', $returnParams, Response::HTTP_SEE_OTHER);
                } catch (OptimisticLockException) {
                    $conflictInfo = $conflictResolver->resolve($user);
                    /** @var User $user */
                    $user = $conflictInfo['fresh'];
                    $form = $this->createForm(UserType::class, $user, ['require_password' => false]);
                }
            }
        }

        // #187: an unsaved edit of one's own record (rejected lockout, failed validation) has
        // already been written into this managed entity, which is also the security token's user.
        // Refresh it, or the session stores the unsaved roles/username and User::isEqualTo()
        // (#184) signs the admin out on the next request. The form keeps the submitted values.
        if ($form->isSubmitted() && null === $conflictInfo && $user === $this->getUser()) {
            $entityManager->refresh($user);
        }

        $softwareCount = $wasActive ? $softwareRepository->countResponsibleSoftware($user) : 0;
        $assetGroupCount = $wasActive ? $assetGroupRepository->countResponsibleAssetGroups($user) : 0;
        $vulnerabilityCount = $wasActive ? $vulnerabilityRepository->countInActiveStatuses($user) : 0;

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
            'return_params' => $returnParams,
            'return_to_show' => $returnToShow,
            'software_count' => $softwareCount,
            'asset_group_count' => $assetGroupCount,
            'vulnerability_count' => $vulnerabilityCount,
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
     * @return array{search: string, filter: string, limit: int, sort: string, direction: string, page: int}
     */
    private function resolveListParams(array $input): array
    {
        $search = (string) ($input['search'] ?? '');
        $filter = (string) ($input['filter'] ?? 'all');
        $limit = (int) ($input['limit'] ?? 10);
        $sort = (string) ($input['sort'] ?? 'username');
        $direction = (string) ($input['direction'] ?? 'asc');
        $page = max(1, (int) ($input['page'] ?? 1));

        if (!\in_array($filter, self::ALLOWED_FILTERS, true)) {
            $filter = 'all';
        }

        if (!\in_array($limit, self::ALLOWED_LIMITS, true)) {
            $limit = 10;
        }

        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'username';
        }

        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'asc';
        }

        return [
            'search' => $search,
            'filter' => $filter,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
        ];
    }
}
