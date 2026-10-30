<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

/**
 * The "Rechtliches" page and the role-dependent SBOM entry point (#83).
 *
 * The point of the role gate on the page is not access control — the route already enforces
 * that (SEC-019). It is that nobody is shown a link that ends in a 403 for them. So the
 * assertion that matters is the *absence* of the link for a user who may not follow it.
 */
final class LegalPageTest extends AbstractWebTestCase
{
    private const string MINIMAL_BOM = '{"bomFormat":"CycloneDX","specVersion":"1.6","version":1,"components":[]}';

    private ?string $temporaryBom = null;

    protected function tearDown(): void
    {
        // Only a file this test wrote is removed — a built SBOM is never touched.
        if (null !== $this->temporaryBom && is_file($this->temporaryBom)) {
            unlink($this->temporaryBom);
        }
        $this->temporaryBom = null;

        parent::tearDown();
    }

    public function testEveryAuthenticatedUserSeesTheLicence(): void
    {
        $this->loginAsSoftwareOwner();
        $crawler = $this->client->request('GET', '/legal');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'AGPL',
            $crawler->filter('body')->text(),
            'The licence is the reason this page exists and is not role-gated.',
        );
    }

    public function testSoftwareOwnerIsNotOfferedTheSbom(): void
    {
        $this->loginAsSoftwareOwner();
        $crawler = $this->client->request('GET', '/legal');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('a[href="/sbom"]'),
            'A link that would answer with 403 must not be shown in the first place.',
        );
    }

    public function testAdminIsOfferedTheSbom(): void
    {
        $this->loginAsAdmin();
        $this->ensureSbomExists();
        $crawler = $this->client->request('GET', '/legal');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a[href="/sbom"]');
        self::assertCount(1, $link);
        // #217: a download, not a page — Turbo must not fetch it as a visit.
        self::assertSame('false', $link->attr('data-turbo'));
    }

    public function testAdminWithoutBuiltSbomGetsAHintInsteadOfALink(): void
    {
        $this->loginAsAdmin();
        if (is_file($this->sbomPath())) {
            self::markTestSkipped('This instance carries a built SBOM, so the absent case cannot be exercised here.');
        }

        $crawler = $this->client->request('GET', '/legal');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/sbom"]'), 'A link that ends in a bare 404 is no download.');
        self::assertStringContainsString('does not include an SBOM', $crawler->filter('body')->text());
    }

    private function sbomPath(): string
    {
        return $this->client->getContainer()->getParameter('kernel.project_dir').'/sbom.json';
    }

    private function ensureSbomExists(): void
    {
        $path = $this->sbomPath();
        if (is_file($path)) {
            return;
        }

        file_put_contents($path, self::MINIMAL_BOM);
        $this->temporaryBom = $path;
    }

    public function testFooterLeadsToTheLegalPage(): void
    {
        $this->loginAsSoftwareOwner();
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('footer a[href="/legal"]')->count(),
            'The footer is the only way into the page.',
        );
    }
}
