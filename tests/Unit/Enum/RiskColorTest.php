<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Enum;

use App\Enum\EffectiveRiskBand;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilityStatus;
use PHPUnit\Framework\TestCase;

/**
 * Guards the dedicated risk colour ramp (issue #14): Severity and effective risk render on a
 * green→yellow→orange→red scale that is visually distinct from the status badges. `orange` and
 * `yellow` are the ramp's signature colours and must never appear in the status palette, so the
 * two axes can never be confused in the same list.
 */
final class RiskColorTest extends TestCase
{
    public function testSeverityColorRamp(): void
    {
        self::assertSame('red', VulnerabilitySeverity::Critical->color());
        self::assertSame('orange', VulnerabilitySeverity::High->color());
        self::assertSame('yellow', VulnerabilitySeverity::Medium->color());
        self::assertSame('green', VulnerabilitySeverity::Low->color());
        self::assertSame('secondary', VulnerabilitySeverity::Informational->color());
    }

    public function testEffectiveRiskBandColorRamp(): void
    {
        self::assertSame('red', EffectiveRiskBand::Critical->color());
        self::assertSame('orange', EffectiveRiskBand::High->color());
        self::assertSame('yellow', EffectiveRiskBand::Medium->color());
        self::assertSame('green', EffectiveRiskBand::Low->color());
    }

    /**
     * The shield glyph is the non-colour cue (issue #14, WCAG 1.4.1): each level maps to a
     * distinct Tabler shield so Critical and High stay apart by SHAPE, not just the red/orange hue.
     */
    public function testSeverityShieldGlyphsAreDistinct(): void
    {
        self::assertSame('shield-x', VulnerabilitySeverity::Critical->icon());
        self::assertSame('shield-exclamation', VulnerabilitySeverity::High->icon());
        self::assertSame('shield-half', VulnerabilitySeverity::Medium->icon());
        self::assertSame('shield-check', VulnerabilitySeverity::Low->icon());
        self::assertSame('shield', VulnerabilitySeverity::Informational->icon());

        $glyphs = array_map(
            static fn (VulnerabilitySeverity $s): string => $s->icon(),
            VulnerabilitySeverity::cases(),
        );
        self::assertSame($glyphs, array_unique($glyphs), 'each severity needs its own shield glyph');
    }

    /** EffectiveRiskBand mirrors the severity glyph set so both risk axes read alike. */
    public function testEffectiveRiskBandShieldGlyphsMirrorSeverity(): void
    {
        self::assertSame(VulnerabilitySeverity::Critical->icon(), EffectiveRiskBand::Critical->icon());
        self::assertSame(VulnerabilitySeverity::High->icon(), EffectiveRiskBand::High->icon());
        self::assertSame(VulnerabilitySeverity::Medium->icon(), EffectiveRiskBand::Medium->icon());
        self::assertSame(VulnerabilitySeverity::Low->icon(), EffectiveRiskBand::Low->icon());
    }

    /**
     * The signature ramp colours (orange/yellow) must not collide with any status colour —
     * this is what keeps the risk axis and the status axis visually separable.
     */
    public function testRampSignatureColorsAreAbsentFromStatusPalette(): void
    {
        $statusColors = array_map(
            static fn (VulnerabilityStatus $s): string => $s->color(),
            VulnerabilityStatus::cases(),
        );

        self::assertNotContains('orange', $statusColors);
        self::assertNotContains('yellow', $statusColors);
    }
}
