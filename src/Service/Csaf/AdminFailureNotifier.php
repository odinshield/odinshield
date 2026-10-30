<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf;

use App\Entity\CsafProviderState;
use App\Service\Notification\OperatorRecipients;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notifies the operators when a CSAF provider crosses the failure threshold, and keeps reminding
 * them while it stays paused (issue #63).
 *
 * Recipients come from {@see OperatorRecipients}: the ROLE_ISB and ROLE_ADMIN holders plus the
 * configured `CSAF_ADMIN_EMAIL`. Before #63 the configured address was the *only* recipient, so an
 * installation that never set it stayed completely silent.
 */
final readonly class AdminFailureNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private OperatorRecipients $recipients,
        #[Autowire('%app.csaf.admin_email%')]
        private ?string $adminEmail,
        #[Autowire('%app.csaf.failure_threshold%')]
        private int $failureThreshold,
    ) {
    }

    public function notifyPaused(CsafProviderState $state): void
    {
        $this->send($state, isReminder: false);
    }

    /**
     * Issue #63: the provider is STILL paused. Sent on a schedule while the pause lasts, because
     * notifyPaused() fires exactly once — at the moment the breaker trips — and findRunnable()
     * then excludes the provider from every later run, so nothing would ever remind again. On
     * Demo that left ABB dead for 74 days after a single unnoticed mail.
     */
    public function notifyStillPaused(CsafProviderState $state, ?int $daysSinceLastSuccess): void
    {
        $this->send($state, isReminder: true, daysSinceLastSuccess: $daysSinceLastSuccess);
    }

    private function send(CsafProviderState $state, bool $isReminder, ?int $daysSinceLastSuccess = null): void
    {
        $to = $this->recipients->forImporterFailure($this->adminEmail);
        if ([] === $to) {
            // M14: nobody at all to tell — neither a role holder nor a configured address. Still
            // worth a warning line so the missing configuration is visible in the logs.
            $this->logger->warning('CSAF provider paused but no notification recipient exists — mail not sent.', [
                'providerKey' => $state->getProviderKey(),
                'consecutiveFailures' => $state->getConsecutiveFailures(),
            ]);

            return;
        }

        $subject = $isReminder
            ? \sprintf('[OdinShield] CSAF-Provider %s weiterhin pausiert', $state->getProviderKey())
            : \sprintf('[OdinShield] CSAF-Provider %s pausiert nach %d Fehlversuchen', $state->getProviderKey(), $this->failureThreshold);

        $intro = $isReminder
            ? \sprintf(
                "Der CSAF-Provider \"%s\" (%s) ist weiterhin pausiert und liefert keine Sicherheitshinweise.\n%s\n",
                $state->getDisplayName(),
                $state->getProviderMetadataUrl(),
                null === $daysSinceLastSuccess
                    ? 'Von dieser Quelle wurde noch nie erfolgreich abgerufen.'
                    : \sprintf('Letzter erfolgreicher Abruf: vor %d Tagen.', $daysSinceLastSuccess),
            )
            : \sprintf(
                "Der CSAF-Provider \"%s\" (%s) wurde nach %d aufeinanderfolgenden Fehlern automatisch pausiert.\n",
                $state->getDisplayName(),
                $state->getProviderMetadataUrl(),
                $state->getConsecutiveFailures(),
            );

        $body = \sprintf(
            "%s\nLetzter Fehler: %s\nZeitpunkt: %s\n\nReset via Konsole:\n  docker compose exec php bin/console odinshield:csaf:resume %s\n",
            $intro,
            $state->getLastErrorMessage() ?? 'n/a',
            $state->getLastErrorAt()?->format('Y-m-d H:i:s') ?? 'n/a',
            $state->getProviderKey(),
        );

        $email = (new Email())
            ->to(...$to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}
