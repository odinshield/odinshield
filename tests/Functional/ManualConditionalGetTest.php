<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Controller\ManualController;

/**
 * Conditional GET on manual pages (#300).
 *
 * The ETag used to come from the Markdown file alone, so an update with unchanged chapters
 * answered 304 and the browser kept HTML whose importmap pointed at assets that no longer
 * existed. The ETag now carries app and manual version; Last-Modified is gone, because a
 * client sending only If-Modified-Since would still get 304 across an update. The dev
 * environment has no conditional GET at all — not covered here (the test kernel runs in
 * "test"), checked in the browser.
 */
final class ManualConditionalGetTest extends AbstractWebTestCase
{
    private const string SLUG = 'faq';

    public function testCurrentEtagIsAnsweredWith304AndKeepsTheHeaders(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/manual/'.self::SLUG);
        self::assertResponseIsSuccessful();
        $etag = (string) $this->client->getResponse()->headers->get('ETag');
        self::assertSame('"'.$this->expectedEtag().'"', $etag);
        self::assertFalse($this->client->getResponse()->headers->has('Last-Modified'));

        $this->client->request('GET', '/manual/'.self::SLUG, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        // SEC-MD10 and the private cache scope hold on the short answer too.
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testEtagFromBeforeTheUpdateGetsTheNewPage(): void
    {
        $this->loginAsAdmin();
        // What a browser cached before #300: Markdown file only, no versions.
        $oldEtag = '"'.dechex(crc32('en|'.self::SLUG.'|'.$this->mtime())).'"';

        $this->client->request('GET', '/manual/'.self::SLUG, server: ['HTTP_IF_NONE_MATCH' => $oldEtag]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testIfModifiedSinceAloneNeverYields304(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/manual/'.self::SLUG, server: [
            'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', time() + 86400).' GMT',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testEveryReleaseIsANewEtag(): void
    {
        $base = ManualController::etag('de', self::SLUG, 1_700_000_000, '2.2.7', '1.3.3');

        self::assertNotSame($base, ManualController::etag('de', self::SLUG, 1_700_000_000, '2.3.0', '1.3.3'), 'App release');
        self::assertNotSame($base, ManualController::etag('de', self::SLUG, 1_700_000_000, '2.2.7', '1.4.0'), 'Manual release');
        self::assertNotSame($base, ManualController::etag('de', self::SLUG, 1_700_000_001, '2.2.7', '1.3.3'), 'Chapter edit');
    }

    private function expectedEtag(): string
    {
        return ManualController::etag('en', self::SLUG, $this->mtime(), $this->stringParameter('app_version'), $this->stringParameter('manual_version'));
    }

    private function stringParameter(string $name): string
    {
        $value = $this->client->getContainer()->getParameter($name);
        self::assertIsString($value, $name);

        return $value;
    }

    /**
     * Same file choice as the controller: the user's locale (the test admin is "en"), else German.
     */
    private function mtime(): int
    {
        $dir = (string) $this->client->getContainer()->getParameter('kernel.project_dir').'/docs/manual';
        $file = is_file($dir.'/en/'.self::SLUG.'.md') ? $dir.'/en/'.self::SLUG.'.md' : $dir.'/de/'.self::SLUG.'.md';

        return (int) filemtime($file);
    }
}
