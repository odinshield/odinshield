<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\EventListener;

use App\EventListener\LogBufferFlushListener;
use App\Monolog\ScannerNoiseActivationStrategy;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Locks in the worker-mode invariant behind the prod error pipeline: a buffering
 * handler chain must hand its records to the mailer within the SAME request.
 *
 * The chain mirrors config/packages/monolog.yaml
 * (`fingers_crossed → group → deduplication → symfony_mailer`); the innermost
 * handler stands in for the mailer. Without the listener the record sits in the
 * deduplication buffer until the worker recycles — that delayed a real error
 * mail by 5.5 days. A Monolog upgrade changing the reset semantics breaks this
 * test rather than silently muting production alerting again.
 */
final class LogBufferFlushListenerTest extends TestCase
{
    private string $deduplicationStore;

    protected function setUp(): void
    {
        $this->deduplicationStore = tempnam(sys_get_temp_dir(), 'monolog-dedup-test-') ?: throw new \RuntimeException('Cannot create deduplication store.');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->deduplicationStore)) {
            unlink($this->deduplicationStore);
        }
    }

    public function testErrorReachesTheMailerOnlyAfterTheListenerRan(): void
    {
        $mailer = $this->mailerSpy();
        $logger = $this->buildLogger($mailer);

        $logger->error('Uncaught PHP Exception');

        self::assertFalse($mailer->hasErrorRecords(), 'The deduplication buffer must hold the record until it is flushed.');

        ($this->listener($logger))($this->terminateEvent());

        self::assertTrue($mailer->hasErrorRecords(), 'kernel.terminate must flush the buffered error to the mailer.');
    }

    public function testResetWithoutAnErrorSendsNothing(): void
    {
        $mailer = $this->mailerSpy();
        $logger = $this->buildLogger($mailer);

        $logger->info('Matched route "app_vulnerability_show".');

        ($this->listener($logger))($this->terminateEvent());

        self::assertSame([], $mailer->getRecords(), 'A request without an error must not reach the mailer.');
    }

    public function testHandlerBuffersAgainAfterTheFlush(): void
    {
        $mailer = $this->mailerSpy();
        $logger = $this->buildLogger($mailer);
        $listener = $this->listener($logger);

        $logger->error('Uncaught PHP Exception');
        $listener($this->terminateEvent());
        self::assertCount(1, $mailer->getRecords());

        // Second request on the same worker: no error, so nothing may pass through.
        // Without the reset this record would stay in the FingersCrossedHandler
        // buffer and ride along with the next request's error report.
        $logger->debug('Checking for authenticator support.');
        $listener($this->terminateEvent());

        self::assertCount(1, $mailer->getRecords(), 'Records of a later, error-free request must not leak into the mail pipeline.');
    }

    /**
     * Stands in for the `symfony_mailer` handler at the end of the chain.
     *
     * `TestHandler::reset()` drops everything it recorded, and the reset under
     * test cascades all the way down — the stock handler would erase the very
     * records this test asserts on. The real handler chain ends in
     * `SymfonyMailerHandler`, whose inherited `reset()` is a no-op.
     */
    private function mailerSpy(): TestHandler
    {
        return new class () extends TestHandler {
            public function reset(): void
            {
            }
        };
    }

    private function buildLogger(TestHandler $mailer): Logger
    {
        $deduplicated = new DeduplicationHandler($mailer, $this->deduplicationStore, Level::Error, 600);
        $group = new GroupHandler([$deduplicated]);
        // stopBuffering: false as in prod; ProdErrorMailChainTest reads it from the config.
        $fingersCrossed = new FingersCrossedHandler($group, new ScannerNoiseActivationStrategy(), 50, true, false);

        return new Logger('test', [$fingersCrossed]);
    }

    private function listener(Logger $logger): LogBufferFlushListener
    {
        return new LogBufferFlushListener($logger);
    }

    private function terminateEvent(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            new Response(),
        );
    }
}
