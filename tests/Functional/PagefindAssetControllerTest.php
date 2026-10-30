<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;

final class PagefindAssetControllerTest extends AbstractWebTestCase
{
    private string $pagefindDir;

    protected function setUp(): void
    {
        parent::setUp();

        $projectDir = $this->client->getKernel()->getProjectDir();
        $this->pagefindDir = $projectDir.'/public/pagefind';

        // Lay down a small fixture so the controller has something to stream during tests
        // even if the production index has not been built in this environment.
        $fs = new Filesystem();
        $fs->mkdir($this->pagefindDir);
        $fs->dumpFile($this->pagefindDir.'/test-fixture.js', "/* fixture */\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->pagefindDir.'/test-fixture.js');
        parent::tearDown();
    }

    public function testUnauthenticatedRequestRedirectsToLogin(): void
    {
        $this->client->request('GET', '/pagefind/test-fixture.js');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAuthenticatedRequestStreamsFileWithCorrectContentType(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/pagefind/test-fixture.js');

        self::assertResponseIsSuccessful();
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        self::assertNotNull($contentType);
        self::assertStringStartsWith('application/javascript', $contentType);

        // BinaryFileResponse streams via sendContent(); rather than reading the body in
        // the test client (which does not invoke the streaming callback), verify that
        // Content-Length matches the fixture file size.
        $expectedLength = (string) filesize($this->pagefindDir.'/test-fixture.js');
        self::assertSame($expectedLength, $this->client->getResponse()->headers->get('Content-Length'));
    }

    public function testAuthenticatedRequestForUnknownFileReturns404(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/pagefind/does-not-exist.js');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPathTraversalAttemptIsRejected(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/pagefind/../config/services.yaml');

        // Either Symfony's path normalisation already collapses it, or our controller's
        // explicit ".." check rejects it. Both outcomes are acceptable; what matters is
        // the file content is NOT served.
        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, 'Path traversal must not return file content.');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('app.markdown.manual_converter', $body, 'services.yaml content must not leak.');
    }

    public function testUnknownExtensionIsRejected(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->pagefindDir.'/test-fixture.bin', 'binary');

        try {
            $this->loginAsAdmin();
            $this->client->request('GET', '/pagefind/test-fixture.bin');

            // Unknown extension → controller refuses to guess MIME type.
            self::assertResponseStatusCodeSame(404);
        } finally {
            @unlink($this->pagefindDir.'/test-fixture.bin');
        }
    }

    public function testWasmContentTypeIsCorrect(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->pagefindDir.'/test-fixture.wasm', "\0asm");

        try {
            $this->loginAsAdmin();
            $this->client->request('GET', '/pagefind/test-fixture.wasm');

            self::assertResponseIsSuccessful();
            $contentType = $this->client->getResponse()->headers->get('Content-Type');
            self::assertSame('application/wasm', $contentType);
        } finally {
            @unlink($this->pagefindDir.'/test-fixture.wasm');
        }
    }

    public function testNoSniffHeaderIsSent(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/pagefind/test-fixture.js');

        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
