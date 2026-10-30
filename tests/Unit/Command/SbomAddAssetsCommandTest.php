<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Command;

use App\Command\SbomAddAssetsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Package URLs built from importmap specifiers (#82).
 *
 * A wrong purl is the worst kind of defect here: the document still validates, still looks
 * complete, and simply names a package no scanner will ever match — which is the one job the
 * SBOM has.
 */
final class SbomAddAssetsCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function specifiers(): iterable
    {
        yield 'plain package' => ['litepicker', 'litepicker', ''];
        yield 'scoped package' => ['@hotwired/turbo', '@hotwired/turbo', ''];
        yield 'plain with subpath' => [
            'tom-select/dist/css/tom-select.default.min.css',
            'tom-select',
            'dist/css/tom-select.default.min.css',
        ];
        yield 'scoped with subpath' => [
            '@tabler/core/dist/css/tabler.min.css',
            '@tabler/core',
            'dist/css/tabler.min.css',
        ];
    }

    #[DataProvider('specifiers')]
    public function testSpecifierSplitsIntoPackageAndSubpath(string $specifier, string $package, string $subpath): void
    {
        self::assertSame([$package, $subpath], SbomAddAssetsCommand::splitSpecifier($specifier));
    }

    public function testPurlEncodesTheScopeAndPutsTheSubpathInTheFragment(): void
    {
        self::assertSame(
            'pkg:npm/%40tabler/core@1.5.1#dist/css/tabler.min.css',
            SbomAddAssetsCommand::purl('@tabler/core', '1.5.1', 'dist/css/tabler.min.css'),
        );
        self::assertSame(
            'pkg:npm/litepicker@2.0.12',
            SbomAddAssetsCommand::purl('litepicker', '2.0.12', ''),
        );
    }

    public function testOnlyVersionedEntriesBecomeComponents(): void
    {
        $components = SbomAddAssetsCommand::componentsFrom([
            'app' => ['path' => './assets/app.js', 'entrypoint' => true],
            '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
            '@hotwired/turbo' => ['version' => '8.0.23'],
        ]);

        self::assertCount(1, $components, 'Path entries are this application or a Composer package, not a JS release.');
        self::assertSame('@hotwired/turbo', $components[0]['name']);
        self::assertSame('pkg:npm/%40hotwired/turbo@8.0.23', $components[0]['purl']);
        self::assertSame('importmap:@hotwired/turbo@8.0.23', $components[0]['bom-ref']);
    }

    public function testProvenanceNamesCreatorAndManufacturerAndKeepsExistingReferences(): void
    {
        $document = SbomAddAssetsCommand::stampProvenance([
            'specVersion' => '1.6',
            'metadata' => ['component' => ['name' => 'odinshield/odinshield', 'externalReferences' => [
                ['type' => 'vcs', 'url' => 'https://example.org/repo.git'],
            ]]],
        ]);

        self::assertSame([['name' => 'OdinShield']], $document['metadata']['authors']);
        self::assertSame(
            ['name' => 'm³ Gommlich', 'url' => ['https://www.odinshield.net']],
            $document['metadata']['manufacturer'],
        );
        self::assertSame('odinshield/odinshield', $document['metadata']['component']['name']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://example.org/repo.git'],
            ['type' => 'website', 'url' => 'https://www.odinshield.net'],
        ], $document['metadata']['component']['externalReferences']);
    }

    public function testPackageSpecifierWinsOverTheImportName(): void
    {
        $components = SbomAddAssetsCommand::componentsFrom([
            'chart.js' => ['version' => '4.5.1', 'package_specifier' => 'chart.js/auto'],
        ]);

        self::assertSame('chart.js', $components[0]['name']);
        self::assertSame('pkg:npm/chart.js@4.5.1#auto', $components[0]['purl']);
        self::assertSame(
            [['name' => 'odinshield:importmap:key', 'value' => 'chart.js']],
            $components[0]['properties'],
            'The importmap key stays traceable even when it differs from the package.',
        );
    }
}
