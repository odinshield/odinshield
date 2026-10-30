<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

// SEC-NEW-006: IS_AUTHENTICATED_FULLY is required so that remember-me tokens
// cannot be used to change the password. A user who authenticated via a
// remember-me cookie (IS_AUTHENTICATED_REMEMBERED) must re-enter their
// credentials before being permitted to change their password.
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ChangePasswordController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'limiter.password_change_limiter')]
        private readonly RateLimiterFactoryInterface $passwordChangeLimiter,
    ) {
    }

    #[Route('/change-password', name: 'app_change_password', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $entityManager, Connection $connection, Security $security): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isMustChangePassword()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SEC-006: cap forced-rotation rate per user (5/hour). Mirrors the
            // /login throttle so the must-change-password flow cannot be abused.
            $limit = $this->passwordChangeLimiter->create($user->getUserIdentifier())->consume(1);
            if (false === $limit->isAccepted()) {
                // L24: a rate-limited forced-change onboarding must not dead-end on a bare 429
                // page — keep the user on the form with a friendly, retry-aware message.
                $minutes = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
                $this->addFlash('error', new TranslatableMessage('security.password_rate_limited', [
                    '%minutes%' => $minutes,
                    '%count%' => $minutes,
                ]));

                return $this->render('security/change_password.html.twig', [
                    'form' => $form,
                ]);
            }

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->setMustChangePassword(false);
            $entityManager->flush();

            // SEC-004: mustChangePassword=true means the prior credential is no
            // longer trusted. Wipe every remember-me token issued under it so
            // concurrent attacker sessions cannot survive the rotation. The
            // current session is terminated by the logout below.
            //
            // Doctrine token provider stores series-based tokens keyed by
            // `username` (see migrations/Version20260506112912.php). Plain
            // parameterised DELETE — no DQL because there is no entity.
            $connection->executeStatement(
                'DELETE FROM rememberme_token WHERE username = :username',
                ['username' => $user->getUserIdentifier()],
            );

            // #188: log out here instead of redirecting to /logout. The logout invalidates the
            // session, so a flash added *after* it lands in the fresh one and the login page can
            // confirm the change — a redirect to /logout destroyed any message on the way. The
            // returned response carries the logout's cookie clearing and its redirect to /login.
            $response = $security->logout(false);
            $this->addFlash('success', 'security.change_password.success');

            return $response ?? $this->redirectToRoute('app_login');
        }

        return $this->render('security/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
