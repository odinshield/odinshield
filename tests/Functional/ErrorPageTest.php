<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * A failed Live Component action shows this page inside Live's error iframe overlay (#221):
 * the home link must navigate the whole window, not the iframe.
 */
final class ErrorPageTest extends AbstractWebTestCase
{
    public function testHomeLinkLeavesTheLiveErrorIframe(): void
    {
        /** @var Environment $twig */
        $twig = $this->client->getContainer()->get('twig');

        foreach ([403, 404, 429, 500, 503] as $statusCode) {
            $html = $twig->render(\sprintf('bundles/TwigBundle/Exception/error%d.html.twig', $statusCode), [
                'status_code' => $statusCode,
                'status_text' => 'Error',
            ]);

            $link = (new Crawler($html))->filter('a.btn-primary');
            self::assertCount(1, $link, \sprintf('Home link missing on the %d page', $statusCode));
            self::assertSame('_top', $link->attr('target'), \sprintf('Home link on the %d page stays inside the iframe', $statusCode));
        }
    }

    /**
     * #204: a rate limit is not "something went wrong" — it has its own wording.
     */
    public function testRateLimitHasItsOwnWording(): void
    {
        /** @var Environment $twig */
        $twig = $this->client->getContainer()->get('twig');
        $html = $twig->render('bundles/TwigBundle/Exception/error429.html.twig', ['status_code' => 429, 'status_text' => 'Too Many Requests']);

        /** @var \Symfony\Contracts\Translation\TranslatorInterface $translator */
        $translator = $this->client->getContainer()->get('translator');
        self::assertStringContainsString($translator->trans('error.429.title'), (new Crawler($html))->filter('h1')->text());
    }
}
