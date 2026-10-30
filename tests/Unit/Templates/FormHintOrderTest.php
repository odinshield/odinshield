<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Issue #215 (UX-28): every form renders widget → hint → error, in that order.
 *
 * A source check rather than a rendered one: the order only shows once a field is invalid, and
 * two of the affected forms are Live Components that a functional request cannot submit. Reading
 * the templates covers every form, including the field macro in software/successor/new.
 */
final class FormHintOrderTest extends TestCase
{
    public function testHintPrecedesErrorsForEveryField(): void
    {
        $templates = (new Finder())->files()->in(\dirname(__DIR__, 3).'/templates')->name('*.twig');
        $checked = 0;
        $wrong = [];

        foreach ($templates as $template) {
            $source = $template->getContents();
            preg_match_all('/help_text\(\s*([\w.]+)\.vars\.id/', $source, $hints, \PREG_OFFSET_CAPTURE);

            foreach ($hints[1] as [$field, $hintOffset]) {
                if (1 !== preg_match('/field_errors\(\s*'.preg_quote($field, '/').'\s*[,)]/', $source, $error, \PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                ++$checked;
                if ($error[0][1] < $hintOffset) {
                    $wrong[] = $template->getRelativePathname().': '.$field;
                }
            }
        }

        self::assertGreaterThan(10, $checked, 'The pattern must still find the forms, or this test checks nothing.');
        self::assertSame([], $wrong, 'Errors rendered before the hint (expected widget → hint → error).');
    }
}
