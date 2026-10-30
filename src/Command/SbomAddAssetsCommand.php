<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds the JavaScript and CSS dependencies from importmap.php to a CycloneDX SBOM (#82).
 *
 * The Composer SBOM cannot see them: AssetMapper resolves its packages independently of
 * Composer, and in the built image the assets survive only as digest-named files
 * (`app-eiGyEpQ.js`) that no scanner can map back to a package name and version. So the
 * authoritative source is `importmap.php` itself, which is why this runs at build time —
 * the file is excluded from the production image.
 *
 * It also stamps the SBOM creator into the document. BSI TR-03183-2 v2.1.0 section 5.2.1
 * requires it and the Composer generator does not write it. The value is the project's web
 * page, which the guideline names as an acceptable substitute where no address exists — and
 * not the internal forge host, which has no business in an artefact that leaves the house
 * (same reasoning as #153).
 *
 * `metadata.manufacturer` names m³ Gommlich, the trading name under which André Gommlich
 * offers OdinShield. The CRA obligations are met voluntarily, whether or not the regulation
 * strictly applies (decided 2026-10-08, #244/#84), so the field is no longer held back.
 *
 * Entries with a `path` are skipped deliberately: they are either this application's own
 * code (`./assets/app.js`) or files shipped inside a Composer package that the Composer SBOM
 * already lists (`./vendor/symfony/stimulus-bundle/…`). Listing them again would invent
 * components that have no upstream release of their own.
 */
#[AsCommand(
    name: 'odinshield:sbom:add-assets',
    description: 'Adds the importmap dependencies to a CycloneDX SBOM produced by Composer.',
)]
final class SbomAddAssetsCommand extends Command
{
    private const string CREATOR_NAME = 'OdinShield';
    private const string CREATOR_URL = 'https://www.odinshield.net';
    private const string MANUFACTURER_NAME = 'm³ Gommlich';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Path to the CycloneDX JSON file to extend, relative to the project or absolute.')]
        string $sbom = 'sbom.json',
    ): int {
        $sbomPath = $this->absolute($sbom);
        $importmapPath = $this->projectDir.'/importmap.php';

        if (!is_file($sbomPath)) {
            $io->error(\sprintf('SBOM not found: %s', $sbomPath));

            return Command::FAILURE;
        }

        if (!is_file($importmapPath)) {
            $io->error(\sprintf('importmap.php not found: %s', $importmapPath));

            return Command::FAILURE;
        }

        $document = json_decode((string) file_get_contents($sbomPath), true);
        if (!\is_array($document) || !isset($document['specVersion'])) {
            $io->error('The SBOM is not a readable CycloneDX JSON document.');

            return Command::FAILURE;
        }

        /** @var array<string, array{path?: string, version?: string, package_specifier?: string, type?: string}> $importmap */
        $importmap = require $importmapPath;

        $components = self::componentsFrom($importmap);
        if ([] === $components) {
            $io->warning('No versioned importmap entries found — the SBOM is unchanged.');

            return Command::SUCCESS;
        }

        $document = self::stampProvenance($document);

        $document['components'] = [...$document['components'] ?? [], ...$components];

        // Every component needs an entry in `dependencies`, or CycloneDX validation fails.
        // These are leaves: the importmap records no transitive graph, so claiming one would
        // be an invention. An empty `dependsOn` is the honest statement.
        $document['dependencies'] = [
            ...$document['dependencies'] ?? [],
            ...array_map(
                static fn (array $c): array => ['ref' => $c['bom-ref'], 'dependsOn' => []],
                $components,
            ),
        ];

        file_put_contents(
            $sbomPath,
            json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );

        $io->success(\sprintf('Added %d importmap components to %s.', \count($components), $sbomPath));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{path?: string, version?: string, package_specifier?: string, type?: string}> $importmap
     *
     * @return list<array{'bom-ref': string, type: string, name: string, version: string, purl: string, scope: string, properties: list<array{name: string, value: string}>}>
     */
    public static function componentsFrom(array $importmap): array
    {
        $components = [];

        foreach ($importmap as $name => $entry) {
            if (!isset($entry['version'])) {
                continue;
            }

            $specifier = $entry['package_specifier'] ?? $name;
            $version = $entry['version'];
            [$package, $subpath] = self::splitSpecifier($specifier);

            $components[] = [
                'bom-ref' => \sprintf('importmap:%s@%s', $name, $version),
                'type' => 'library',
                'name' => $package,
                'version' => $version,
                'purl' => self::purl($package, $version, $subpath),
                'scope' => 'required',
                // Keeps the trail back to the importmap key, which is what the application
                // actually imports — it is not always the package name.
                'properties' => [['name' => 'odinshield:importmap:key', 'value' => $name]],
            ];
        }

        return $components;
    }

    /**
     * Stamps creator and manufacturer into the document metadata.
     *
     * No `email` key: the guideline allows a URL where no address exists, but CycloneDX's
     * `email` expects an address and `bom-ref` is an internal identifier — a URL in either
     * is a schema abuse a validator may reject. The URL belongs on the component it
     * describes, as an external reference, which is what CycloneDX models URLs with.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function stampProvenance(array $document): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = \is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
        /** @var array<string, mixed> $component */
        $component = \is_array($metadata['component'] ?? null) ? $metadata['component'] : [];
        /** @var list<mixed> $references */
        $references = \is_array($component['externalReferences'] ?? null) ? array_values($component['externalReferences']) : [];

        $metadata['authors'] = [['name' => self::CREATOR_NAME]];
        $metadata['manufacturer'] = ['name' => self::MANUFACTURER_NAME, 'url' => [self::CREATOR_URL]];
        $component['externalReferences'] = [...$references, ['type' => 'website', 'url' => self::CREATOR_URL]];
        $metadata['component'] = $component;
        $document['metadata'] = $metadata;

        return $document;
    }

    /**
     * Splits an importmap specifier into package name and subpath.
     *
     * `@tabler/core/dist/css/tabler.min.css` is not a package — the package is `@tabler/core`
     * and the rest is a path inside it. A purl that carries the path in the name position is
     * a package a scanner will never find, which defeats the purpose of the document.
     *
     * @return array{0: string, 1: string} package, subpath ('' when there is none)
     */
    public static function splitSpecifier(string $specifier): array
    {
        $segments = explode('/', $specifier);
        $take = str_starts_with($specifier, '@') ? 2 : 1;

        $package = implode('/', \array_slice($segments, 0, $take));
        $subpath = implode('/', \array_slice($segments, $take));

        return [$package, $subpath];
    }

    /**
     * Builds a package URL per the purl spec: the scope's `@` is percent-encoded, the subpath
     * goes into the fragment.
     */
    public static function purl(string $package, string $version, string $subpath): string
    {
        $purl = 'pkg:npm/'.str_replace('@', '%40', $package).'@'.$version;

        return '' === $subpath ? $purl : $purl.'#'.$subpath;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
    }
}
