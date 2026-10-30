<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Manual;

use App\Controller\ManualController;
use App\Markdown\ManualMarkdownConverterFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that every anchor in ManualController::ROUTE_MAP points to a heading
 * that actually exists in the corresponding chapter — per locale, because the
 * English chapters have English headings and so English slugs (#212). Without
 * this guard, a heading rename in docs/manual/{de,en}/*.md silently breaks the
 * contextual help deep-link — the redirect still resolves, but lands at the top
 * of the page. ManualHelpAnchorTest checks the same through the real redirect.
 */
final class ManualRouteMapAnchorsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public static function routeMapProvider(): iterable
    {
        $reflection = new \ReflectionClass(ManualController::class);
        /** @var array<string, array{slug: string, anchor?: array{de: string, en: string}}> $routeMap */
        $routeMap = $reflection->getConstant('ROUTE_MAP');

        foreach ($routeMap as $route => $target) {
            foreach (['de', 'en'] as $locale) {
                yield $route.' ('.$locale.')' => [$route, $locale, $target['slug'], $target['anchor'][$locale] ?? null];
            }
        }
    }

    #[DataProvider('routeMapProvider')]
    public function testAnchorResolvesInChapter(string $route, string $locale, string $slug, ?string $anchor): void
    {
        $reflection = new \ReflectionClass(ManualController::class);
        /** @var list<string> $validSlugs */
        $validSlugs = $reflection->getConstant('VALID_SLUGS');

        self::assertContains(
            $slug,
            $validSlugs,
            sprintf('Route %s targets slug "%s" which is not in VALID_SLUGS.', $route, $slug),
        );

        $path = \dirname(__DIR__, 3).'/docs/manual/'.$locale.'/'.$slug.'.md';
        self::assertFileExists($path, sprintf('Chapter file missing for slug "%s".', $slug));

        if (null === $anchor) {
            // Route only opens the chapter — the file-exists check above is sufficient.
            return;
        }

        $headingIds = self::extractHeadingIds((string) file_get_contents($path));

        self::assertContains(
            $anchor,
            $headingIds,
            sprintf(
                'Route %s expects anchor "#%s" in /manual/%s (%s), but the chapter has no heading with that slug. Available: %s',
                $route,
                $anchor,
                $slug,
                $locale,
                implode(', ', $headingIds),
            ),
        );
    }

    /**
     * Render the markdown with the same converter the controller uses and
     * extract the IDs HeadingPermalinkExtension assigns. The extension
     * inserts <a id="…" href="#…" class="manual-heading-anchor"> *before*
     * the heading text — IDs live on the anchor, not on the <h2>.
     *
     * @return list<string>
     */
    private static function extractHeadingIds(string $markdown): array
    {
        $converter = ManualMarkdownConverterFactory::create();
        $html = (string) $converter->convert($markdown);

        $matches = [];
        preg_match_all('/<a\s+id="([^"]+)"\s+href="#\1"\s+class="manual-heading-anchor"/u', $html, $matches);

        return $matches[1];
    }
}
