<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service;

use App\Entity\User;
use App\Entity\Vulnerability;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MailNotifier
{
    public const string NOTICE_EMAIL_CHANGED = 'email_changed';
    public const string NOTICE_PASSWORD_CHANGED = 'password_changed';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_SENDER')] private readonly string $senderDsn,
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    /**
     * #269: tells the account's PREVIOUS address that it was replaced, so a silent takeover
     * (password known, address switched, assignment mails redirected) is noticed by the owner.
     * The new address is shown partly masked.
     */
    public function notifyEmailChanged(User $user, string $previousEmail, bool $byAdmin): void
    {
        $this->sendSecurityNotice($user, $previousEmail, self::NOTICE_EMAIL_CHANGED, [
            'new_email' => self::maskEmail($user->getEmail()),
            'by_admin' => $byAdmin,
        ]);
    }

    /**
     * #269: a voluntary password change is reported to the address the account had before
     * this save — if the same save also replaced the address, the owner still gets it.
     */
    public function notifyPasswordChanged(User $user, string $recipientEmail): void
    {
        $this->sendSecurityNotice($user, $recipientEmail, self::NOTICE_PASSWORD_CHANGED, [
            'by_admin' => false,
        ]);
    }

    /**
     * "andre@example.com" → "an***@example.com": enough for the owner to recognise or reject
     * the address, without handing a full address to whoever reads the old mailbox.
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $keep = mb_strlen($local) > 3 ? 2 : 1;

        return mb_substr($local, 0, $keep).'***'.substr($email, $at);
    }

    /**
     * Security notices are sent regardless of emailNotifications — they are not assignment
     * notifications. A failed send must never undo the save that triggered it: log and go on.
     *
     * @param array<string, mixed> $context
     */
    private function sendSecurityNotice(User $user, string $recipientEmail, string $notice, array $context): void
    {
        if ('' === $recipientEmail) {
            return;
        }

        $locale = $user->getLocale() ?? $this->defaultLocale;

        $message = (new TemplatedEmail())
            ->from(Address::create($this->senderDsn))
            ->to(new Address($recipientEmail, $user->getFullName() ?? $user->getUsername() ?? $recipientEmail))
            ->locale($locale)
            ->subject('[OdinShield] '.$this->translator->trans('email.security_notice.'.$notice.'.subject', locale: $locale))
            ->htmlTemplate('email/security_notice.html.twig')
            ->textTemplate('email/security_notice.txt.twig')
            ->context($context + [
                'user' => $user,
                'notice' => $notice,
                'changed_at' => new \DateTimeImmutable(),
                'locale' => $locale,
            ]);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface|MessengerExceptionInterface $e) {
            $this->logger->error('Security notice mail could not be sent.', [
                'notice' => $notice,
                'user' => $user->getUserIdentifier(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Sends an assignment notification when a vulnerability is assigned to a new user.
     *
     * Pass the responsible user that existed before the change (null for new vulnerabilities).
     * No email is sent if the assignee did not change, has notifications disabled, or has no email.
     */
    public function notifyVulnerabilityAssigned(Vulnerability $vulnerability, ?User $previousAssignee): void
    {
        $assignee = $vulnerability->getResponsibleUser();

        if (null === $assignee) {
            return;
        }

        if ($assignee === $previousAssignee) {
            return;
        }

        if (!$assignee->isEmailNotifications()) {
            return;
        }

        $email = $assignee->getEmail();
        if ('' === $email) {
            return;
        }

        $vulnerabilityUrl = $this->urlGenerator->generate(
            'app_vulnerability_show',
            ['uuid' => $vulnerability->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // #209: a user who never picked a language gets the application default — not whatever
        // locale the triggering request happened to have.
        $locale = $assignee->getLocale() ?? $this->defaultLocale;

        $message = (new TemplatedEmail())
            ->from(Address::create($this->senderDsn))
            ->to(new Address($email, $assignee->getFullName() ?? $assignee->getUsername() ?? $email))
            // M13/#209: the whole mail speaks the recipient's language. The subject is translated
            // here; locale() makes the body renderer switch locale for the templates too —
            // otherwise they rendered in the language of the request that triggered the mail
            // (or the default in the importer, which has no request).
            ->locale($locale)
            ->subject('[OdinShield] '.$this->translator->trans(
                'email.vulnerability_assigned.subject',
                ['%title%' => $vulnerability->getTitle()],
                locale: $locale,
            ))
            ->htmlTemplate('email/vulnerability_assigned.html.twig')
            ->textTemplate('email/vulnerability_assigned.txt.twig')
            ->context([
                'assignee' => $assignee,
                'vulnerability' => $vulnerability,
                'vulnerability_url' => $vulnerabilityUrl,
                'locale' => $locale,
            ]);

        $this->mailer->send($message);
    }
}
