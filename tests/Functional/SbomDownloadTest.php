<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

/**
 * The SBOM download is restricted to ROLE_ADMIN and ROLE_ISB (#82, SEC-019).
 *
 * The document lists every component with its exact version and is therefore a map of the
 * attack surface. A Software-Owner is the interesting negative case: a privileged role that
 * still must not see it.
 *
 * The file itself is produced at build time and is absent from a checkout, so the test
 * supplies a minimal one and removes it again — an existing file is never touched.
 */
final class SbomDownloadTest extends AbstractWebTestCase
{
    private const string MINIMAL_BOM = '{"bomFormat":"CycloneDX","specVersion":"1.6","version":1,"components":[]}';

    private ?string $temporaryBom = null;

    protected function tearDown(): void
    {
        if (null !== $this->temporaryBom && is_file($this->temporaryBom)) {
            unlink($this->temporaryBom);
        }
        $this->temporaryBom = null;

        parent::tearDown();
    }

    public function testAdminDownloadsTheSbom(): void
    {
        $this->loginAsAdmin();
        $this->ensureSbomExists();

        $this->client->request('GET', '/sbom');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.cyclonedx+json');

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('-sbom.json', $disposition, 'The filename carries the release it belongs to.');
    }

    public function testSoftwareOwnerIsRefused(): void
    {
        $this->loginAsSoftwareOwner();
        $this->ensureSbomExists();

        $this->client->request('GET', '/sbom');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingSbomIsNotFoundRatherThanAnError(): void
    {
        $this->loginAsAdmin();

        $path = $this->sbomPath();
        if (is_file($path)) {
            self::markTestSkipped('This instance carries a built SBOM, so the absent case cannot be exercised here.');
        }

        $this->client->request('GET', '/sbom');

        self::assertResponseStatusCodeSame(404);
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
}
