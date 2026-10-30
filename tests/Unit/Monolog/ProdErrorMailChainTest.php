<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Monolog;

use App\Monolog\ScannerNoiseActivationStrategy;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Bounds what a single error hands to the prod mail and stderr sinks (#145).
 *
 * A long-running Messenger message — a whole CSAF import run — is flushed only
 * once, when `ResetServicesListener` resets the logger at its end. With
 * Monolog's default `stop_buffering: true` the first error switched the
 * `fingers_crossed` handler to pass-through for the rest of that message: one
 * connection failure produced a 3.8 MB mail with 2,097 records, almost all of
 * them the INFO/DEBUG output of the import that kept running after it.
 *
 * The handler options are read from config/packages/monolog.yaml rather than
 * copied, so dropping `stop_buffering: false` there fails this test.
 */
final class ProdErrorMailChainTest extends TestCase
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

    public function testErrorMidRunSendsOnlyTheContextBeforeTheError(): void
    {
        $mailer = $this->sinkSpy();
        $stderr = $this->sinkSpy();
        $logger = $this->buildLogger($mailer, $stderr);
        $bufferSize = $this->bufferSize();

        $this->logImportNoise($logger, $bufferSize * 2);
        $logger->error('CSAF provider failed');
        $this->logImportNoise($logger, 1000);
        $logger->reset();

        foreach (['mailer' => $mailer, 'stderr' => $stderr] as $sink => $handler) {
            $records = $handler->getRecords();
            self::assertCount($bufferSize, $records, \sprintf('The %s sink must receive the buffered context up to the error, not the rest of the run.', $sink));
            self::assertSame('CSAF provider failed', $records[$bufferSize - 1]->message, \sprintf('The error must be the last record the %s sink receives.', $sink));
        }
    }

    public function testDistinctErrorsInTheSameRunAreAllReported(): void
    {
        $mailer = $this->sinkSpy();
        $logger = $this->buildLogger($mailer, $this->sinkSpy());

        $logger->error('CSAF provider failed: stackable');
        $this->logImportNoise($logger, 200);
        $logger->error('CSAF provider failed: redhat');
        $this->logImportNoise($logger, 200);
        $logger->reset();

        $errors = array_values(array_map(
            static fn ($record): string => $record->message,
            array_filter($mailer->getRecords(), static fn ($record): bool => Level::Error === $record->level),
        ));

        self::assertSame(['CSAF provider failed: stackable', 'CSAF provider failed: redhat'], $errors);
        self::assertLessThanOrEqual(2 * $this->bufferSize(), \count($mailer->getRecords()));
    }

    public function testRunWithoutAnErrorSendsNothing(): void
    {
        $mailer = $this->sinkSpy();
        $stderr = $this->sinkSpy();
        $logger = $this->buildLogger($mailer, $stderr);

        $this->logImportNoise($logger, 200);
        $logger->reset();

        self::assertSame([], $mailer->getRecords());
        self::assertSame([], $stderr->getRecords());
    }

    /**
     * #312: the idle worker's once-a-second LISTEN/NOTIFY line filled up to 45 of the 50 buffered
     * records of a CSAF error mail. It runs on its own channel, which the prod handler excludes.
     */
    public function testIdleWorkerLineStaysOutOfTheErrorMailBuffer(): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 3).'/config/services.yaml');
        $listener = \is_array($services) ? ($services['services']['messenger.transport.doctrine.pg_notify_on_idle_listener'] ?? null) : null;
        self::assertIsArray($listener, 'config/services.yaml must redefine the LISTEN/NOTIFY idle listener.');
        self::assertContains(['name' => 'monolog.logger', 'channel' => 'messenger_idle'], $listener['tags'] ?? []);

        self::assertContains('!messenger_idle', $this->prodMainHandlerOptions()['channels'] ?? []);
    }

    private function logImportNoise(Logger $logger, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            0 === $i % 3
                ? $logger->debug('HTTP GET https://security.access.redhat.com/data/csaf/v2/advisories/…')
                : $logger->info('CSAF document skipped (rebuild only, no content change)');
        }
    }

    /**
     * Stands in for `symfony_mailer` and `nested`. The reset under test cascades
     * through the whole chain, and the stock `TestHandler::reset()` would erase
     * the records asserted on; see LogBufferFlushListenerTest.
     */
    private function sinkSpy(): TestHandler
    {
        return new class () extends TestHandler {
            public function reset(): void
            {
            }
        };
    }

    /**
     * `main → group_main → [nested, deduplicated_mailer → symfony_mailer]`, with
     * `stderr` in place of `nested`.
     */
    private function buildLogger(TestHandler $mailer, TestHandler $stderr): Logger
    {
        $options = $this->prodMainHandlerOptions();

        $deduplicated = new DeduplicationHandler($mailer, $this->deduplicationStore, Level::Error, 600);
        $group = new GroupHandler([$stderr, $deduplicated]);
        $fingersCrossed = new FingersCrossedHandler(
            $group,
            new ScannerNoiseActivationStrategy(),
            $this->bufferSize(),
            true,
            (bool) ($options['stop_buffering'] ?? true),
        );

        return new Logger('csaf', [$fingersCrossed]);
    }

    private function bufferSize(): int
    {
        $bufferSize = $this->prodMainHandlerOptions()['buffer_size'] ?? null;
        self::assertIsInt($bufferSize, 'The prod `main` handler must set an explicit buffer_size.');

        return $bufferSize;
    }

    /**
     * @return array<string, mixed>
     */
    private function prodMainHandlerOptions(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        $main = \is_array($config) ? ($config['when@prod']['monolog']['handlers']['main'] ?? null) : null;
        self::assertIsArray($main, 'config/packages/monolog.yaml must define when@prod.monolog.handlers.main.');
        self::assertSame('fingers_crossed', $main['type'] ?? null);

        return $main;
    }
}
