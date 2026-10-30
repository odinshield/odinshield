<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use League\CommonMark\MarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// SEC-007: Explicit gate — defence-in-depth against future access_control refactoring.
// REMEMBERED, not FULLY: remember-me is honoured for everyday browsing (SEC-016, #186).
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route('/manual')]
class ManualController extends AbstractController
{
    /**
     * Symfony route name → manual landing target. `anchor` (optional) deep-links into the
     * matching heading of the destination page so the contextual help button lands on the
     * relevant section instead of the page top. Anchor IDs are the slugs HeadingPermalinkExtension
     * generates from the heading text — and the English chapters have English headings, so each
     * anchor is given per locale (#212). ManualHelpAnchorTest renders every target in both
     * languages and fails when a heading changes under an anchor.
     */
    private const array ROUTE_MAP = [
        'app_dashboard' => ['slug' => 'erste-schritte', 'anchor' => self::DASHBOARD],
        'app_vulnerability_index' => ['slug' => 'schwachstellen', 'anchor' => self::VULN_LIST],
        'app_vulnerability_new' => ['slug' => 'schwachstellen', 'anchor' => self::VULN_LIFECYCLE],
        'app_vulnerability_show' => ['slug' => 'schwachstellen', 'anchor' => self::VULN_LIFECYCLE],
        'app_vulnerability_edit' => ['slug' => 'schwachstellen', 'anchor' => self::VULN_RESPONSIBILITY],
        'app_asset_group_index' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_asset_group_new' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_asset_group_show' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_asset_group_edit' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_asset_type_index' => ['slug' => 'inventar'],
        'app_asset_type_new' => ['slug' => 'inventar'],
        'app_asset_type_show' => ['slug' => 'inventar'],
        'app_asset_type_edit' => ['slug' => 'inventar'],
        'app_software_index' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_software_new' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_software_show' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        'app_software_edit' => ['slug' => 'inventar', 'anchor' => self::INVENTORY],
        // The successor flow is explained in the FAQ ("Was passiert beim Anlegen einer
        // Nachfolgerversion?"); _create re-renders the form after a validation error.
        'app_software_successor_new' => ['slug' => 'faq', 'anchor' => self::FAQ_INVENTORY],
        'app_software_successor_create' => ['slug' => 'faq', 'anchor' => self::FAQ_INVENTORY],
        'app_vendor_index' => ['slug' => 'inventar'],
        'app_vendor_new' => ['slug' => 'inventar'],
        'app_vendor_show' => ['slug' => 'inventar'],
        'app_vendor_edit' => ['slug' => 'inventar'],
        'app_user_index' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_audit_index' => ['slug' => 'team', 'anchor' => self::TEAM_AUDIT],
        'app_import_status' => ['slug' => 'team', 'anchor' => self::TEAM_ADMIN_ISB],
        'app_legal' => ['slug' => 'team', 'anchor' => self::TEAM_ADMIN_ISB],
        'app_user_new' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_show' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_edit' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_group_index' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_group_new' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_group_show' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_user_group_edit' => ['slug' => 'team', 'anchor' => self::TEAM_SETUP],
        'app_settings' => ['slug' => 'einstellungen'],
    ];

    private const array DASHBOARD = ['de' => 'das-dashboard-lesen', 'en' => 'reading-the-dashboard'];
    private const array VULN_LIST = ['de' => 'effizient-arbeiten-filter--suche', 'en' => 'work-efficiently-filter--search'];
    private const array VULN_LIFECYCLE = ['de' => 'der-lebenszyklus-einer-schwachstelle', 'en' => 'the-vulnerability-lifecycle'];
    private const array VULN_RESPONSIBILITY = ['de' => 'verantwortlichkeiten-festlegen', 'en' => 'set-responsibilities'];
    private const array INVENTORY = ['de' => 'aufbau-ihres-inventars', 'en' => 'building-your-inventory'];
    private const array TEAM_SETUP = ['de' => 'ein-neues-teammitglied-einrichten', 'en' => 'setting-up-a-new-team-member'];
    private const array TEAM_AUDIT = ['de' => 'die-audit-timeline-als-nachweis', 'en' => 'the-audit-timeline-as-evidence'];
    private const array TEAM_ADMIN_ISB = ['de' => 'was-administrator-und-isb-zusätzlich-sehen', 'en' => 'what-the-administrator-and-the-iso-see-in-addition'];
    private const array FAQ_INVENTORY = ['de' => 'inventar--software', 'en' => 'inventory--software'];

    private const array VALID_SLUGS = [
        'erste-schritte',
        'schwachstellen',
        'inventar',
        'team',
        'einstellungen',
        'faq',
        'glossar',
        'tipps',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire(service: 'app.markdown.manual_converter')]
        private readonly MarkdownConverter $markdownConverter,
        private readonly CacheInterface $cache,
        #[Autowire('%app_version%')]
        private readonly string $appVersion,
        #[Autowire('%manual_version%')]
        private readonly string $manualVersion,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * #300: the page is Markdown *and* the shell around it — template, translations, and an
     * importmap whose asset digests change with every release. An ETag built from the Markdown
     * file alone answered 304 after an update and left the browser with HTML pointing at assets
     * that no longer exist (the manual's JS 404s). The versions make every release a new ETag.
     */
    public static function etag(string $locale, string $slug, int $mtime, string $appVersion, string $manualVersion): string
    {
        return dechex(crc32(implode('|', [$locale, $slug, $mtime, $appVersion, $manualVersion])));
    }

    #[Route('', name: 'app_manual', methods: ['GET'])]
    public function context(Request $request): Response
    {
        $fromRoute = $request->query->getString('from');
        $mapping = self::ROUTE_MAP[$fromRoute] ?? ['slug' => 'erste-schritte'];

        $url = $this->generateUrl('app_manual_page', ['slug' => $mapping['slug']]);
        if (isset($mapping['anchor'])) {
            // Same locale choice as page(): the chapter rendered there decides which slugs exist.
            $locale = \in_array($request->getLocale(), ['de', 'en'], true) ? $request->getLocale() : 'de';
            // Non-ASCII characters in the fragment (umlauts in German anchors) must be
            // percent-encoded so the Location header stays RFC-7231-clean.
            $url .= '#'.rawurlencode($mapping['anchor'][$locale]);
        }

        return $this->redirect($url);
    }

    #[Route('/{slug}', name: 'app_manual_page', requirements: ['slug' => '[a-z][a-z0-9-]*'], methods: ['GET'])]
    public function page(string $slug, Request $request): Response
    {
        if (!\in_array($slug, self::VALID_SLUGS, true)) {
            throw $this->createNotFoundException();
        }

        $locale = $request->getLocale();
        if (!\in_array($locale, ['de', 'en'], true)) {
            $locale = 'de';
        }

        $filePath = sprintf('%s/docs/manual/%s/%s.md', $this->projectDir, $locale, $slug);
        if (!file_exists($filePath)) {
            $filePath = sprintf('%s/docs/manual/de/%s.md', $this->projectDir, $slug);
        }

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException();
        }

        $mtime = filemtime($filePath);
        if (false === $mtime) {
            throw $this->createNotFoundException();
        }

        // Conditional-GET shell — built before rendering so a fresh client can be answered
        // with 304 without touching the cache or Twig at all.
        // #300: ETag only, no Last-Modified — the Markdown mtime says nothing about the shell,
        // and a client sending only If-Modified-Since would get 304 across an update. In dev
        // there is no conditional GET at all: templates and assets change without a version
        // bump, and a 304 would keep the old HTML with dead asset URLs.
        $response = new Response();
        if ('dev' !== $this->environment) {
            $response->setEtag(self::etag($locale, $slug, $mtime, $this->appVersion, $this->manualVersion));
        }
        $response->setPrivate();
        // SEC-MD10: keep authenticated manual pages out of crawler indices even when
        // a misconfigured proxy strips the auth cookie.
        $response->headers->set('X-Robots-Tag', 'noindex');

        if ($response->isNotModified($request)) {
            return $response;
        }

        // Cache is keyed on the file mtime, so a deploy with new markdown produces a new
        // cache slot; the old entry expires naturally via LRU. No explicit TTL needed.
        $cacheKey = sprintf('manual.%s.%s.%d', $locale, $slug, $mtime);
        $htmlContent = $this->cache->get($cacheKey, function (ItemInterface $item) use ($filePath): string {
            // 7 days bounds the orphan entries from past deploys; live entries are refreshed
            // whenever the source file changes (mtime moves into the cache key).
            $item->expiresAfter(7 * 86400);

            $markdown = file_get_contents($filePath);
            if (false === $markdown) {
                throw new \RuntimeException('Manual file read failed: '.$filePath);
            }

            return (string) $this->markdownConverter->convert($markdown);
        });

        return $this->render('manual/page.html.twig', [
            'html_content' => $htmlContent,
            'current_slug' => $slug,
        ], $response);
    }
}
