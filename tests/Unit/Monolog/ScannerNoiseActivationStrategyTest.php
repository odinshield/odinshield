<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Monolog;

use App\Monolog\ScannerNoiseActivationStrategy;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Guards the signal-to-noise rules of the prod mail/stderr activation strategy.
 *
 * The scenarios mirror the real records observed during the security scan in PENTEST/:
 * scanner 404s and FrankenPHP/session warnings must NOT activate, real errors must.
 */
final class ScannerNoiseActivationStrategyTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function record(Level $level, string $channel = 'request', array $context = [], string $message = 'msg', array $extra = []): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable('2026-06-05T17:11:00+02:00'),
            $channel,
            $level,
            $message,
            $context,
            $extra,
        );
    }

    public function testBelowActionLevelNeverActivates(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        self::assertFalse($strategy->isHandlerActivated($this->record(Level::Debug)));
        self::assertFalse($strategy->isHandlerActivated($this->record(Level::Info)));
        self::assertFalse($strategy->isHandlerActivated($this->record(Level::Warning)));
    }

    public function testGenericErrorActivates(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        self::assertTrue($strategy->isHandlerActivated($this->record(Level::Error)));
        self::assertTrue($strategy->isHandlerActivated($this->record(Level::Critical)));
    }

    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function httpExceptionProvider(): iterable
    {
        yield '400 malformed body' => [new BadRequestHttpException(), false];
        yield '404 unknown route' => [new NotFoundHttpException('No route found'), false];
        yield '405 wrong method' => [new MethodNotAllowedHttpException(['GET']), false];
        yield '403 access denied still alerts' => [new AccessDeniedHttpException(), true];
        yield '503 server-side still alerts' => [new ServiceUnavailableHttpException(), true];
    }

    #[DataProvider('httpExceptionProvider')]
    public function testHttpStatusExclusion(\Throwable $exception, bool $expected): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(Level::Error, 'request', ['exception' => $exception]);

        self::assertSame($expected, $strategy->isHandlerActivated($record));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function phpWarningProvider(): iterable
    {
        yield 'multipart boundary' => [
            'Warning: frankenphp_handle_request(): Missing boundary in multipart/form-data POST data',
            false,
        ];
        yield 'illegal session id (read)' => [
            'Warning: SessionHandler::read(): Session ID is too long or contains illegal characters. Only the A-Z, a-z, 0-9, "-", and "," characters are allowed',
            false,
        ];
        yield 'genuine code warning still alerts' => [
            'Warning: Undefined array key "foo"',
            true,
        ];
    }

    #[DataProvider('phpWarningProvider')]
    public function testBenignPhpWarningExclusion(string $message, bool $expected): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(Level::Error, 'php', message: $message);

        self::assertSame($expected, $strategy->isHandlerActivated($record));
    }

    public function testBenignPatternOnNonPhpChannelStillActivates(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        // Same wording, but not on the "php" channel → treated as a real error.
        $record = $this->record(
            Level::Error,
            'app',
            message: 'frankenphp_handle_request(): Missing boundary in multipart/form-data',
        );

        self::assertTrue($strategy->isHandlerActivated($record));
    }

    /**
     * #77 — Turbo prefetches links on hover. A prefetch that lands on a forbidden route is a
     * correct 403 that nobody clicked and nobody sees: Turbo discards a failed prefetch silently,
     * so the mail would name a URL the user never visited.
     */
    public function testForbiddenFromPrefetchDoesNotActivate(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(
            Level::Error,
            context: ['exception' => new AccessDeniedHttpException('Access Denied.')],
            extra: ['prefetch' => true],
        );

        self::assertFalse($strategy->isHandlerActivated($record));
    }

    public function testUnauthorizedFromPrefetchDoesNotActivate(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(
            Level::Error,
            context: ['exception' => new UnauthorizedHttpException('Basic')],
            extra: ['prefetch' => true],
        );

        self::assertFalse($strategy->isHandlerActivated($record));
    }

    /**
     * The counterpart that keeps the change honest: the same status from a real navigation is
     * still worth a mail — that decision predates #77 and stands.
     */
    public function testForbiddenFromRealNavigationStillActivates(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(
            Level::Error,
            context: ['exception' => new AccessDeniedHttpException('Access Denied.')],
        );

        self::assertTrue($strategy->isHandlerActivated($record));
    }

    /**
     * A prefetch is not a blanket excuse: a server error during one is still a server error.
     */
    public function testServerErrorDuringPrefetchStillActivates(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(
            Level::Error,
            context: ['exception' => new ServiceUnavailableHttpException()],
            extra: ['prefetch' => true],
        );

        self::assertTrue($strategy->isHandlerActivated($record));
    }

    /**
     * Records without the mark must behave exactly as before — the flag is opt-in, absence of it
     * must never be read as "prefetch".
     */
    public function testMissingPrefetchFlagBehavesLikeARealRequest(): void
    {
        $strategy = new ScannerNoiseActivationStrategy();

        $record = $this->record(
            Level::Error,
            context: ['exception' => new AccessDeniedHttpException('Access Denied.')],
            extra: ['prefetch' => false],
        );

        self::assertTrue($strategy->isHandlerActivated($record));
    }
}
