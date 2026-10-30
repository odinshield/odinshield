<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Controller\ManualController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every contextual help link lands on an existing section, in German and in English (#212).
 *
 * The anchors are the slugs of the chapter headings, and the English chapters have English
 * headings — a German slug in an English chapter silently dropped the reader at the page top.
 * This walks the whole help map: each route exists, and for both locales the redirect target
 * names a heading that the rendered chapter actually carries. Renaming a heading without the
 * map turns this red.
 */
final class ManualHelpAnchorTest extends AbstractWebTestCase
{
    public function testEveryMappedRouteExists(): void
    {
        /** @var RouterInterface $router */
        $router = $this->client->getContainer()->get('router');

        foreach (array_keys($this->routeMap()) as $route) {
            self::assertNotNull($router->getRouteCollection()->get($route), sprintf('Help map names an unknown route "%s".', $route));
        }
    }

    public function testEveryHelpLinkLandsOnAnExistingHeadingInBothLanguages(): void
    {
        $user = $this->loginAsAdmin();

        foreach (['de', 'en'] as $locale) {
            $user->setLocale($locale);
            $this->em()->flush();
            $this->client->loginUser($user);

            foreach ($this->routeMap() as $route => $target) {
                $this->client->request('GET', '/manual?from='.$route);
                $location = (string) $this->client->getResponse()->headers->get('Location');
                self::assertStringStartsWith('/manual/'.$target['slug'], $location, $route);

                $fragment = parse_url($location, \PHP_URL_FRAGMENT);
                if (!isset($target['anchor'])) {
                    self::assertNull($fragment, sprintf('%s (%s) has no anchor and must land on the page top.', $route, $locale));
                    continue;
                }
                self::assertIsString($fragment, sprintf('%s (%s) must carry an anchor.', $route, $locale));

                $crawler = $this->client->request('GET', '/manual/'.$target['slug']);
                $ids = $crawler->filter('a.manual-heading-anchor')->each(static fn ($a): string => (string) $a->attr('id'));
                self::assertContains(rawurldecode($fragment), $ids, sprintf('%s (%s): no heading "%s" in chapter "%s".', $route, $locale, rawurldecode($fragment), $target['slug']));
            }
        }
    }

    /**
     * @return array<string, array{slug: string, anchor?: array{de: string, en: string}}>
     */
    private function routeMap(): array
    {
        /** @var array<string, array{slug: string, anchor?: array{de: string, en: string}}> $map */
        $map = (new \ReflectionClass(ManualController::class))->getConstant('ROUTE_MAP');

        return $map;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
