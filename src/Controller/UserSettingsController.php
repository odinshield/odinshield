<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\UserSettingsType;
use App\Service\MailNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

// SEC-NEW-006: IS_AUTHENTICATED_FULLY is required so that remember-me tokens
// cannot be used to access or modify user settings (including password change).
// A user authenticated via a remember-me cookie must re-enter their credentials.
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
final class UserSettingsController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'limiter.password_change_limiter')]
        private readonly RateLimiterFactoryInterface $passwordChangeLimiter,
    ) {
    }

    public function __invoke(Request $request, EntityManagerInterface $entityManager, Connection $connection, MailNotifier $mailNotifier): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        // #269: read before handleRequest() writes the submitted address onto the entity.
        $previousEmail = $user->getEmail();

        $form = $this->createForm(UserSettingsType::class, $user);
        $form->handleRequest($request);

        // SEC-006: cap re-auth attempts per user (5/hour) to mirror the /login throttle so the
        // current-password re-check cannot be brute-forced. Charged BEFORE isValid() — a wrong
        // current password invalidates the form and must count too (#193). The form's PRE_SUBMIT
        // listener marks currentPassword required exactly when a re-check runs (password or
        // e-mail change).
        if ($form->isSubmitted() && $form->get('currentPassword')->isRequired()) {
            $limit = $this->passwordChangeLimiter->create($user->getUserIdentifier())->consume(1);
            if (false === $limit->isAccepted()) {
                // handleRequest() already wrote the submitted values onto the managed User;
                // drop them so nothing of this rejected attempt can be flushed later.
                $entityManager->refresh($user);

                // L24: keep the user on the settings form with a friendly, retry-aware
                // message instead of a bare 429 error page.
                $minutes = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
                $this->addFlash('error', new TranslatableMessage('user_settings.current_password.rate_limited', [
                    '%minutes%' => $minutes,
                    '%count%' => $minutes,
                ]));

                return $this->render('user_settings/index.html.twig', [
                    'form' => $form,
                ]);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $passwordChanged = false;
            if (null !== $plainPassword && '' !== $plainPassword) {
                // SEC-001: require the current password before accepting a new one.
                // The unmapped `currentPassword` field carries the UserPassword
                // constraint; if the form validated, the constraint already passed.
                // The mere presence of the field on the submitted form is what
                // forces the user to re-prove possession of the active credential.
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                $passwordChanged = true;
            }

            $entityManager->flush();

            // SEC-004 (audit L5): a voluntary password change must invalidate every remember-me
            // token issued under the old credential, exactly like the forced-rotation flow — a
            // stolen remember-me cookie must not survive a password change.
            if ($passwordChanged) {
                $connection->executeStatement(
                    'DELETE FROM rememberme_token WHERE username = :username',
                    ['username' => $user->getUserIdentifier()],
                );
            }

            // #269: security notices go to the address before this save, so a takeover that
            // switches the address and/or password is reported to the real owner.
            if ($previousEmail !== $user->getEmail()) {
                $mailNotifier->notifyEmailChanged($user, $previousEmail, byAdmin: false);
            }
            if ($passwordChanged) {
                $mailNotifier->notifyPasswordChanged($user, $previousEmail);
            }

            $this->addFlash('success', 'settings.updated');

            // L27: return to the settings page (not the dashboard) so the user sees their
            // saved values. The former session '_locale' write was dead code — the locale is
            // applied per-request from the User entity by LocaleListener, never read from session.
            $response = $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);

            $secure = $request->isSecure();
            $theme = $user->getTheme();
            if (null !== $theme) {
                $response->headers->setCookie(
                    Cookie::create('theme', $theme->value, new \DateTimeImmutable('+1 year'), '/', secure: $secure, httpOnly: false, sameSite: 'lax')
                );
            } else {
                $response->headers->clearCookie('theme', '/', null, $secure, false);
            }

            return $response;
        }

        return $this->render('user_settings/index.html.twig', [
            'form' => $form,
        ]);
    }
}
