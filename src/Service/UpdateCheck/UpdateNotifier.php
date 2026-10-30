<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\UpdateCheck;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * #223: one mail per new release to the active administrators — they are the ones who install
 * it (decided 2026-10-30: mail to admin; the ISB sees the dashboard hint). Plain operator mail in
 * German, like the importer notices (AdminFailureNotifier).
 */
final readonly class UpdateNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_SENDER')]
        private string $sender,
    ) {
    }

    public function notifyAdmins(AvailableUpdate $update): void
    {
        $to = array_values(array_filter(array_map(
            static fn (User $u): string => $u->getEmail(),
            $this->userRepository->findActiveByAnyRole([User::ROLE_ADMIN]),
        )));
        if ([] === $to) {
            $this->logger->warning('New OdinShield version published but no active administrator to tell.', [
                'version' => $update->version,
            ]);

            return;
        }

        $subject = $update->security
            ? \sprintf('[OdinShield] Sicherheitsupdate verfügbar: Version %s', $update->version)
            : \sprintf('[OdinShield] Neue Version verfügbar: %s', $update->version);

        $body = \sprintf(
            "OdinShield %s ist veröffentlicht%s. Diese Installation läuft mit Version %s.\n\n%s%s".
            "Den Hinweis sehen ISB und Administratoren auch auf dem Dashboard, bis die Installation aktualisiert ist.\n",
            $update->version,
            null === $update->releasedAt ? '' : ' (am '.$update->releasedAt->format('d.m.Y').')',
            $update->currentVersion,
            $update->security ? "Das Release enthält Sicherheitskorrekturen. Spielen Sie es zeitnah ein.\n\n" : '',
            null === $update->url ? '' : "Release-Informationen:\n  ".$update->url."\n\n",
        );

        try {
            $this->mailer->send((new Email())
                ->from(Address::create($this->sender))
                ->to(...$to)
                ->subject($subject)
                ->text($body));
        } catch (TransportExceptionInterface|MessengerExceptionInterface $e) {
            $this->logger->warning('Update notice mail could not be sent.', [
                'version' => $update->version,
                'exception' => $e,
            ]);
        }
    }
}
