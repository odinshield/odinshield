<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@tabler/core' => ['version' => '1.6.1'],
    '@tabler/core/dist/css/tabler.min.css' => ['version' => '1.6.1', 'type' => 'css'],
    '@fontsource/inter/index.min.css' => ['version' => '5.3.0', 'type' => 'css'],
    '@orchidjs/sifter' => ['version' => '1.1.0'],
    '@orchidjs/unicode-variants' => ['version' => '1.1.2'],
    'tom-select/dist/css/tom-select.default.min.css' => ['version' => '2.6.2', 'type' => 'css'],
    'litepicker' => ['version' => '2.0.12'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    'chart.js' => ['version' => '4.5.1'],
    'idiomorph' => ['version' => '0.7.4'],
    'frankenphp-hot-reload' => ['version' => '1.0.1'],
    '@kurkle/color' => ['version' => '0.3.4'],
    '@fontsource/inter' => ['version' => '5.3.0'],
    'tom-select' => ['version' => '2.6.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
];
