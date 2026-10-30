<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\EventListener;

use App\EventListener\EffectiveRiskListener;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Locks in the worker-mode invariant: an exception between onFlush and postFlush
 * must not leak (occurrence id, score) pairs into the next request. The listener
 * implements ResetInterface; if anyone removes that, this test breaks.
 */
final class EffectiveRiskListenerTest extends TestCase
{
    public function testImplementsResetInterface(): void
    {
        self::assertInstanceOf(ResetInterface::class, new EffectiveRiskListener());
    }

    public function testResetClearsPendingUpdates(): void
    {
        $listener = new EffectiveRiskListener();
        $property = new \ReflectionProperty($listener, 'pendingUpdates');

        $property->setValue($listener, [['id' => 1, 'score' => 42], ['id' => 2, 'score' => 7]]);
        self::assertCount(2, $property->getValue($listener));

        $listener->reset();

        self::assertSame([], $property->getValue($listener));
    }
}
