<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Monolog;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks log records that belong to a Turbo prefetch, so the activation strategy can weigh them
 * differently without ever touching RequestStack (#77).
 *
 * Turbo 8 prefetches links on hover; a prefetch that lands on a forbidden route produces a
 * perfectly correct 403 that nobody clicked and nobody sees — Turbo discards a failed prefetch
 * silently. Mailing the operator about it names a URL the user never visited.
 *
 * Why a processor plus a listener rather than reading the request where the decision is made:
 * {@see ScannerNoiseActivationStrategy} deliberately has no RequestStack, because under
 * FrankenPHP worker mode getMainRequest() is not reliably populated when a record is evaluated —
 * that is exactly why the stock HttpCodeActivationStrategy leaked 404s to the mailer. The header
 * therefore has to travel INTO the record while the request is still on the stack: the listener
 * takes it straight off the RequestEvent, the processor stamps it onto every record.
 *
 * The flag is rewritten on every main request and cleared on terminate, so nothing leaks between
 * two requests served by the same worker thread.
 *
 * I-02 (#200): what counts as a prefetch. Turbo's prefetch is a fetch() and JavaScript cannot set
 * `Sec-*` headers, so Turbo sends `X-Sec-Purpose: prefetch` — measured 2026-10-20 in Chromium:
 * `X-Sec-Purpose: prefetch`, `Sec-Fetch-Site: same-origin`, `Sec-Fetch-Mode: cors`, and no
 * `Sec-Purpose` at all. Until then only `Sec-Purpose` was read, so #77 never matched a Turbo
 * prefetch. Both headers are accepted now (`Sec-Purpose` stays for native browser prefetch), but
 * only together with `Sec-Fetch-Site: same-origin`, which the browser sets itself and page
 * scripts cannot. A bare `Sec-Purpose: prefetch` from curl therefore no longer silences the
 * 401/403 alert. Any header can be forged by a script that knows the rule; this only stops the
 * one-header shortcut, and the access log keeps every request either way.
 */
#[AsMonologProcessor]
final class PrefetchProcessor implements ProcessorInterface
{
    /**
     * Native browser prefetch sends `Sec-Purpose: prefetch`, prerendering adds a token
     * (`prefetch;prerender`), hence the substring match rather than an equality check. Turbo
     * sends the same value as `X-Sec-Purpose`.
     */
    private const string PREFETCH_TOKEN = 'prefetch';

    /** @var list<string> */
    private const array PURPOSE_HEADERS = ['X-Sec-Purpose', 'Sec-Purpose'];

    private bool $isPrefetch = false;

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getRequest()->headers;
        $purpose = '';
        foreach (self::PURPOSE_HEADERS as $name) {
            $purpose .= ' '.mb_strtolower((string) $headers->get($name, ''));
        }

        $this->isPrefetch = str_contains($purpose, self::PREFETCH_TOKEN)
            && 'same-origin' === $headers->get('Sec-Fetch-Site');
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(): void
    {
        $this->isPrefetch = false;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (!$this->isPrefetch) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'prefetch' => true]);
    }
}
