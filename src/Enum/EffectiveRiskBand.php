<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Enum;

/**
 * Classifies a numeric effective-risk score (severity × criticality × exposure × data sensitivity,
 * × 2 when actively exploited) into human-readable bands. Maximum possible score = 5 × 4 × 3 × 4 × 2 = 480.
 */
enum EffectiveRiskBand: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * Tabler colour token for the dedicated risk ramp (green→yellow→orange→red) that the
     * shield badge renders — deliberately distinct from the status palette so the two axes
     * never share a colour in the same list. See `templates/_include/_risk_badge.html.twig`.
     */
    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'orange',
            self::Medium => 'yellow',
            self::Low => 'green',
        };
    }

    /**
     * Tabler shield glyph whose *shape* encodes the band — the non-colour cue that keeps Critical
     * and High apart even when red/orange sit close in hue (WCAG 1.4.1). Mirrors
     * VulnerabilitySeverity::icon() so both risk axes read alike. Rendered inline by
     * `templates/_include/_risk_badge.html.twig`.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'shield-x',
            self::High => 'shield-exclamation',
            self::Medium => 'shield-half',
            self::Low => 'shield-check',
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 120 => self::Critical,
            $score >= 60 => self::High,
            $score >= 20 => self::Medium,
            default => self::Low,
        };
    }
}
