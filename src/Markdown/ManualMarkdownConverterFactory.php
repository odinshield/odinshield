<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

final class ManualMarkdownConverterFactory
{
    public static function create(): MarkdownConverter
    {
        // SEC-M01/M02: Allowing raw HTML for the manual because source files are local
        // and part of the codebase (trusted source). This enables rich components
        // like Bento grids and SVG icons.
        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'html_class' => 'manual-heading-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'before',
                'min_heading_level' => 2,
                'max_heading_level' => 4,
                'title' => '',
                'symbol' => '#',
                'aria_hidden' => true,
            ],
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        return new MarkdownConverter($environment);
    }
}
