<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Monolog;

use App\Monolog\PrefetchProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * #77 — the processor carries the "this was a prefetch" fact from the request into the log
 * record, so that ScannerNoiseActivationStrategy can act on it without a RequestStack.
 */
final class PrefetchProcessorTest extends TestCase
{
    private const array TURBO_PREFETCH = ['X-Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'same-origin'];

    public function testRecordIsUnmarkedWithoutARequest(): void
    {
        $processor = new PrefetchProcessor();

        self::assertArrayNotHasKey('prefetch', $processor($this->record())->extra);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('prefetchHeaders')]
    public function testPrefetchHeaderMarksTheRecord(array $headers): void
    {
        $processor = new PrefetchProcessor();
        $processor->onKernelRequest($this->requestEvent($headers));

        self::assertTrue($processor($this->record())->extra['prefetch']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function prefetchHeaders(): iterable
    {
        // Exactly what Turbo 8 sent in Chromium on hover, measured 2026-10-20 (#200).
        yield 'turbo prefetch' => [['X-Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors']];
        yield 'native prefetch' => [['Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'same-origin']];
        // Prerendering appends a token, hence the substring match in the processor.
        yield 'prerender' => [['Sec-Purpose' => 'prefetch;prerender', 'Sec-Fetch-Site' => 'same-origin']];
        yield 'upper case' => [['X-Sec-Purpose' => 'Prefetch', 'Sec-Fetch-Site' => 'same-origin']];
    }

    /**
     * I-02 (#200): a prefetch header alone — what a scripted probe sends — must not silence the
     * 401/403 alert; the browser-set Sec-Fetch-Site has to agree.
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('forgedPrefetchHeaders')]
    public function testForgedPrefetchHeaderLeavesTheRecordUnmarked(array $headers): void
    {
        $processor = new PrefetchProcessor();
        $processor->onKernelRequest($this->requestEvent($headers));

        self::assertArrayNotHasKey('prefetch', $processor($this->record())->extra);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function forgedPrefetchHeaders(): iterable
    {
        yield 'Sec-Purpose alone' => [['Sec-Purpose' => 'prefetch']];
        yield 'X-Sec-Purpose alone' => [['X-Sec-Purpose' => 'prefetch']];
        yield 'cross-site' => [['X-Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'cross-site']];
        yield 'user-initiated' => [['Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'none']];
    }

    public function testOrdinaryNavigationLeavesTheRecordUnmarked(): void
    {
        $processor = new PrefetchProcessor();
        $processor->onKernelRequest($this->requestEvent([]));

        self::assertArrayNotHasKey('prefetch', $processor($this->record())->extra);
    }

    /**
     * Under FrankenPHP worker mode one thread serves many requests in a row. A prefetch must not
     * bleed into the next request — otherwise a genuine 403 would be silenced by the flag of its
     * predecessor.
     */
    public function testFlagDoesNotSurviveIntoTheNextRequest(): void
    {
        $processor = new PrefetchProcessor();

        $processor->onKernelRequest($this->requestEvent(self::TURBO_PREFETCH));
        self::assertTrue($processor($this->record())->extra['prefetch']);

        $processor->onKernelTerminate();
        self::assertArrayNotHasKey('prefetch', $processor($this->record())->extra);

        $processor->onKernelRequest($this->requestEvent(self::TURBO_PREFETCH));
        $processor->onKernelRequest($this->requestEvent([]));
        self::assertArrayNotHasKey('prefetch', $processor($this->record())->extra);
    }

    /**
     * A sub-request must not overwrite what the main request established.
     */
    public function testSubRequestDoesNotClearTheFlag(): void
    {
        $processor = new PrefetchProcessor();
        $processor->onKernelRequest($this->requestEvent(self::TURBO_PREFETCH));

        $kernel = $this->createStub(HttpKernelInterface::class);
        $processor->onKernelRequest(new RequestEvent($kernel, Request::create('/fragment'), HttpKernelInterface::SUB_REQUEST));

        self::assertTrue($processor($this->record())->extra['prefetch']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestEvent(array $headers): RequestEvent
    {
        $request = Request::create('/user/new');
        $request->headers->add($headers);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2026-09-11T18:00:00+02:00'), 'request', Level::Error, 'msg');
    }
}
