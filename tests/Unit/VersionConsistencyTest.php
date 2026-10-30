<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Single-source-of-truth guard for the application version.
 *
 * The version lives in /VERSION (one line). Every other place that mentions
 * the version is a mirror that MUST agree:
 *
 *   - environment/.env  → APP_VERSION
 *   - Dockerfile        → ARG APP_VERSION (no static label values, both LABEL
 *                          lines must use ${APP_VERSION})
 *
 * services.yaml resolves at runtime via %env(APP_VERSION)% — no static value
 * to drift, but the fallback parameter is checked so we don't accidentally
 * ship a fallback like "0.0.0-dev" that overshadows a missing env in prod.
 *
 * Drift between any of these mirrors fails this test, surfacing the issue at
 * `make test` instead of in a deployed image label.
 */
final class VersionConsistencyTest extends TestCase
{
    private const string PROJECT_ROOT = __DIR__.'/../..';

    public function testVersionFileIsPresentAndNonEmpty(): void
    {
        $version = $this->readVersionFile();

        self::assertNotSame('', $version, '/VERSION must contain a non-empty version string.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[\w.+-]+)?$/', $version, sprintf('/VERSION value "%s" is not a SemVer string.', $version));
    }

    public function testEnvFileMirrorsVersionFile(): void
    {
        $version = $this->readVersionFile();
        $envContent = (string) file_get_contents(self::PROJECT_ROOT.'/environment/.env');

        self::assertMatchesRegularExpression(
            '/^APP_VERSION='.preg_quote($version, '/').'$/m',
            $envContent,
            sprintf('environment/.env must contain APP_VERSION=%s (single source: /VERSION).', $version),
        );
    }

    public function testDockerfileLabelsUseBuildArg(): void
    {
        $dockerfile = (string) file_get_contents(self::PROJECT_ROOT.'/Dockerfile');

        // Both LABEL lines must reference ${APP_VERSION} — no hard-coded version strings.
        $matches = [];
        preg_match_all('/org\.opencontainers\.image\.version="([^"]+)"/', $dockerfile, $matches);

        self::assertNotEmpty($matches[1], 'Dockerfile must define org.opencontainers.image.version labels.');

        foreach ($matches[1] as $value) {
            self::assertSame(
                '${APP_VERSION}',
                $value,
                'Dockerfile LABEL org.opencontainers.image.version must reference ${APP_VERSION}, '
                .'never a hard-coded version. Found: '.$value,
            );
        }
    }

    public function testDockerfileDeclaresAppVersionArg(): void
    {
        $dockerfile = (string) file_get_contents(self::PROJECT_ROOT.'/Dockerfile');

        // Each FROM stage that uses ${APP_VERSION} in a LABEL must declare ARG APP_VERSION.
        // Two stages reference the version: frankenphp_prod_builder and frankenphp_prod.
        $argCount = preg_match_all('/^ARG\s+APP_VERSION/m', $dockerfile);

        self::assertGreaterThanOrEqual(2, $argCount, sprintf('Dockerfile must declare ARG APP_VERSION in each stage that uses ${APP_VERSION} (found %d).', $argCount));
    }

    public function testMakefileBuildPassesAppVersionArg(): void
    {
        $makefile = (string) file_get_contents(self::PROJECT_ROOT.'/Makefile');

        self::assertMatchesRegularExpression(
            '/--build-arg\s+APP_VERSION=\$\(ODINSHIELD_VERSION\)/',
            $makefile,
            'Makefile build target must pass --build-arg APP_VERSION=$(ODINSHIELD_VERSION) to docker build.',
        );

        self::assertMatchesRegularExpression(
            '/ODINSHIELD_VERSION\s*=\s*\$\(shell\s+cat\s+VERSION/',
            $makefile,
            'Makefile must derive ODINSHIELD_VERSION from /VERSION via $(shell cat VERSION ...).',
        );
    }

    public function testServicesYamlReadsAppVersionFromEnv(): void
    {
        $servicesYaml = (string) file_get_contents(self::PROJECT_ROOT.'/config/services.yaml');

        self::assertStringContainsString(
            "app_version: '%env(default:app_version_fallback:APP_VERSION)%'",
            $servicesYaml,
            'services.yaml app_version must read from APP_VERSION env with fallback parameter.',
        );

        // The fallback should be a placeholder dev version, not a real one — otherwise a
        // missing env in prod silently ships a stale label.
        self::assertMatchesRegularExpression(
            "/app_version_fallback:\s*'0\.0\.0-/",
            $servicesYaml,
            'app_version_fallback must be a clearly-non-prod placeholder (e.g. 0.0.0-dev).',
        );
    }

    public function testFrankenphpVersionConsistentBetweenMakefileAndDockerfile(): void
    {
        $makefileValue = $this->readMakefileVariable('FRANKENPHP_VERSION');
        $dockerfileDefault = $this->readDockerfileArgDefault('FRANKENPHP_VERSION');

        self::assertSame(
            $makefileValue,
            $dockerfileDefault,
            sprintf(
                'Drift detected: Makefile FRANKENPHP_VERSION=%s but Dockerfile ARG FRANKENPHP_VERSION default=%s. Update both together.',
                $makefileValue,
                $dockerfileDefault ?? '(none)',
            ),
        );
    }

    public function testPagefindVersionConsistentBetweenMakefileAndDockerfile(): void
    {
        $makefileValue = $this->readMakefileVariable('PAGEFIND_VERSION');
        $dockerfileDefault = $this->readDockerfileArgDefault('PAGEFIND_VERSION');

        self::assertSame(
            $makefileValue,
            $dockerfileDefault,
            sprintf(
                'Drift detected: Makefile PAGEFIND_VERSION=%s but Dockerfile ARG PAGEFIND_VERSION default=%s. Update both together.',
                $makefileValue,
                $dockerfileDefault ?? '(none)',
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function claudeStageVersionProvider(): iterable
    {
        foreach (['NODE_VERSION', 'CLAUDE_CODE_VERSION', 'DEV_BROWSER_VERSION', 'BIOME_VERSION', 'MARKDOWNLINT_VERSION', 'SHFMT_VERSION', 'SHELLCHECK_VERSION', 'HADOLINT_VERSION', 'GITLEAKS_VERSION', 'SYMFONY_CLI_VERSION', 'REUSE_VERSION'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The claude stage installs the same tool versions the host targets pin (make fix and make ci
     * behave the same in the container and on the host).
     */
    #[DataProvider('claudeStageVersionProvider')]
    public function testClaudeStageVersionConsistentBetweenMakefileAndDockerfile(string $name): void
    {
        $makefileValue = $this->readMakefileVariable($name);
        self::assertNotNull($makefileValue, sprintf('Makefile must define %s.', $name));

        self::assertSame(
            $makefileValue,
            $this->readDockerfileArgDefault($name),
            sprintf('Drift detected: Makefile %s=%s but the Dockerfile ARG default differs. Update both together.', $name, $makefileValue),
        );
    }

    public function testBiomeVersionMatchesBiomeJsonSchema(): void
    {
        $biomeJson = (string) file_get_contents(self::PROJECT_ROOT.'/biome.json');

        self::assertMatchesRegularExpression(
            '#biomejs\.dev/schemas/'.preg_quote((string) $this->readMakefileVariable('BIOME_VERSION'), '#').'/schema\.json#',
            $biomeJson,
            'Makefile BIOME_VERSION must match the $schema version in biome.json.',
        );
    }

    public function testMakefileBuildPassesPagefindVersionArg(): void
    {
        $makefile = (string) file_get_contents(self::PROJECT_ROOT.'/Makefile');

        self::assertMatchesRegularExpression(
            '/--build-arg\s+PAGEFIND_VERSION=\$\(PAGEFIND_VERSION\)/',
            $makefile,
            'Makefile build target must pass --build-arg PAGEFIND_VERSION=$(PAGEFIND_VERSION) to docker build.',
        );
    }

    public function testManualVersionConsistentBetweenMakefileAndEnv(): void
    {
        $makefileValue = $this->readMakefileVariable('MANUAL_VERSION');
        self::assertNotNull($makefileValue, 'Makefile must define MANUAL_VERSION.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[\w.+-]+)?$/', $makefileValue, sprintf('Makefile MANUAL_VERSION value "%s" is not a SemVer string.', $makefileValue));

        $envContent = (string) file_get_contents(self::PROJECT_ROOT.'/environment/.env');
        self::assertMatchesRegularExpression(
            '/^MANUAL_VERSION='.preg_quote($makefileValue, '/').'$/m',
            $envContent,
            sprintf('environment/.env MANUAL_VERSION must mirror Makefile MANUAL_VERSION=%s.', $makefileValue),
        );
    }

    public function testDeployComposeDoesNotOverrideManualVersion(): void
    {
        // MANUAL_VERSION must stay a build-time property of the image, never a
        // per-stage knob. The manual's Markdown and its Pagefind index both ship
        // inside the image, so the number describes content the image already
        // contains; `composer dump-env prod` bakes it in alongside APP_VERSION.
        // A runtime override could only ever make the footer claim a version that
        // does not match the shipped chapters — the exact drift the mirrors above
        // exist to prevent, reintroduced one layer down where no test would see it.
        // A wrong manual version is fixed by releasing a new image, not per stage.
        foreach (['compose.deploy.yaml', '.env.deploy.example'] as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*(#\s*)?MANUAL_VERSION\s*[:=]/m',
                (string) file_get_contents(self::PROJECT_ROOT.'/'.$file),
                sprintf('%s must not set MANUAL_VERSION — it is baked into the image at build time and must not be overridable per stage.', $file),
            );
        }
    }

    public function testServicesYamlReadsManualVersionFromEnv(): void
    {
        $servicesYaml = (string) file_get_contents(self::PROJECT_ROOT.'/config/services.yaml');

        self::assertStringContainsString(
            "manual_version: '%env(default:manual_version_fallback:MANUAL_VERSION)%'",
            $servicesYaml,
            'services.yaml manual_version must read from MANUAL_VERSION env with fallback parameter.',
        );

        self::assertMatchesRegularExpression(
            "/manual_version_fallback:\s*'0\.0\.0-/",
            $servicesYaml,
            'manual_version_fallback must be a clearly-non-prod placeholder (e.g. 0.0.0-dev).',
        );
    }

    private function readMakefileVariable(string $name): ?string
    {
        $makefile = (string) file_get_contents(self::PROJECT_ROOT.'/Makefile');

        if (1 === preg_match('/^'.preg_quote($name, '/').'\s*=\s*(.+?)\s*$/m', $makefile, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function readDockerfileArgDefault(string $name): ?string
    {
        $dockerfile = (string) file_get_contents(self::PROJECT_ROOT.'/Dockerfile');

        if (1 === preg_match('/^ARG\s+'.preg_quote($name, '/').'=(.+?)\s*$/m', $dockerfile, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function readVersionFile(): string
    {
        $contents = file_get_contents(self::PROJECT_ROOT.'/VERSION');
        self::assertNotFalse($contents, '/VERSION file must exist and be readable.');

        return trim($contents);
    }
}
