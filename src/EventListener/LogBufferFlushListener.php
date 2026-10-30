<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use Monolog\ResettableInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the buffering prod log handlers at the end of every request.
 *
 * The prod chain is `fingers_crossed → group → deduplication → symfony_mailer`
 * (see config/packages/monolog.yaml). `DeduplicationHandler` extends
 * `BufferHandler`: it collects records and only hands them to the mailer in
 * `flush()`, which is reached via `close()` or `reset()`.
 *
 * Under FrankenPHP worker mode neither happens per request. The container is
 * never torn down, and `Kernel::boot()` runs `services_resetter` only at the
 * START of the NEXT request — on the very worker thread that saw the error.
 * On a low-traffic instance that delayed a real error mail by 5.5 days
 * (error 2026-08-04 22:10, mail 2026-08-10 09:14), and a worker dying in
 * between drops the mail entirely.
 *
 * Resetting at `kernel.terminate` fixes two things at once:
 *  1. the mail is dispatched in the same request that produced the error;
 *  2. the `FingersCrossedHandler` buffer (up to 50 records) no longer bleeds
 *     into the error report of an unrelated later request.
 *
 * It no longer has to end a pass-through phase: `main` sets
 * `stop_buffering: false` (#145), so the handler keeps buffering after an
 * error instead of forwarding every later record, DEBUG included, into the
 * unbounded deduplication buffer.
 *
 * Deduplication survives the change: its store is a file in /tmp and works
 * across requests regardless of when the buffer is flushed.
 *
 * CLI needs no equivalent — the Messenger worker already resets services per
 * message ({@see \Symfony\Component\Messenger\EventListener\ResetServicesListener}),
 * and short-lived commands flush on destruction. A single message can run for
 * minutes (a whole CSAF import), so the mail for an error in it still leaves
 * only at the end of that message; `stop_buffering: false` is what keeps the
 * rest of the run out of that mail.
 *
 * The priority must stay BELOW the one of `ProfilerListener` (-1024): the reset
 * also clears Monolog's `DebugProcessor`, which is where the dev profiler's log
 * panel reads its records from during `lateCollect()`. Running last also means
 * anything the other terminate listeners log still makes it into this flush.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
class LogBufferFlushListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($this->logger instanceof ResettableInterface) {
            $this->logger->reset();
        }
    }
}
