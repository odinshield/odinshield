<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The copyright line names the licence instead of reserving all rights (#146).
 *
 * "All rights reserved" contradicted the AGPL, which grants exactly those
 * rights. The full SPDX identifier is used, not the deprecated short form
 * `AGPL-3.0`, matching the file headers and LICENSE.md. Only the application
 * footer links it: the licence page requires a full login, so a link on the
 * login or change-password page would lead nowhere.
 */
final class FooterLicenseNoticeTest extends AbstractWebTestCase
{
    private const string LICENSE = 'AGPL-3.0-or-later';

    public function testAppFooterNamesTheLicenseAndLinksIt(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $footer = $crawler->filter('footer.footer');
        self::assertCount(1, $footer);

        $text = $footer->text();
        self::assertStringContainsString(\sprintf('© %s OdinShield', date('Y')), $text);
        $this->assertNoReservedRights($text);

        $licenseLink = $footer->selectLink(self::LICENSE);
        self::assertCount(1, $licenseLink, 'The licence identifier must link to the legal-notices page.');
        // The licence lives on the "Rechtliches" page since #83 — the SPDX identifier in the
        // footer still links straight to it.
        self::assertSame('/legal', $licenseLink->attr('href'));
        self::assertCount(0, $footer->filter('a[href="#"]'), 'The footer must not carry dead links.');
    }

    public function testLoginPageNamesTheLicenseWithoutLink(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertStandaloneNotice($crawler);
    }

    public function testChangePasswordPageNamesTheLicenseWithoutLink(): void
    {
        // The form is only shown during a forced rotation; everyone else is sent to the dashboard.
        $user = $this->loginAsAdmin();
        $user->setMustChangePassword(true);
        $this->client->getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $this->client->request('GET', '/change-password');

        self::assertResponseIsSuccessful();
        $this->assertStandaloneNotice($crawler);
    }

    public function testManualFooterNamesTheLicense(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/manual/erste-schritte');

        self::assertResponseIsSuccessful();
        self::assertSame(
            \sprintf('© %s OdinShield — %s', date('Y'), self::LICENSE),
            $crawler->filter('footer.manual-footer')->text(),
        );
    }

    private function assertStandaloneNotice(Crawler $crawler): void
    {
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString(\sprintf('© %s OdinShield — %s', date('Y'), self::LICENSE), $text);
        self::assertCount(0, $crawler->selectLink(self::LICENSE), 'The licence page requires a full login; no link here.');
        $this->assertNoReservedRights($text);
    }

    private function assertNoReservedRights(string $text): void
    {
        self::assertStringNotContainsString('Alle Rechte vorbehalten', $text);
        self::assertStringNotContainsString('All rights reserved', $text);
    }
}
