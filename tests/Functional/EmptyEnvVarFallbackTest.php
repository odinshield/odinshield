<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An operations variable that is *set but empty* must fall back to its default.
 *
 * `compose.deploy.yaml` passes optional settings as `${FOO:-}`, so on a stage where the operator
 * did not configure them they arrive as an empty string — set, not absent. Combined with a cast,
 * the order of the env processors then decides between "works" and "the command dies on start":
 *
 *     %env(default:param:int:FOO)%   cast runs first  -> "Non-numeric env var FOO cannot be
 *                                                        cast to int", whole command aborts
 *     %env(int:default:param:FOO)%   default runs first -> falls back, then casts. Correct.
 *
 * Found on Q with v2.0.6 (2026-09-11): `odinshield:csaf:run --sync` aborted on
 * `CSAF_PAUSE_REMINDER_DAYS` with exactly that message. The parameter had shipped in v2.0.5 with
 * issue #63, so the scheduled import had been failing on that stage ever since — silently, because
 * nobody ran the command by hand.
 */
class EmptyEnvVarFallbackTest extends KernelTestCase
{
    /**
     * Every operations variable that is optional AND casts to a non-string type. Adding a new one
     * without adding it here is exactly how this class of bug comes back.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function castingEnvVars(): iterable
    {
        yield 'CSAF pause reminder' => ['CSAF_PAUSE_REMINDER_DAYS', 'app.csaf.pause_reminder_days', 7];
        yield 'exploitation pause reminder' => ['EXPLOITATION_PAUSE_REMINDER_DAYS', 'app.exploitation.pause_reminder_days', 7];
    }

    #[DataProvider('castingEnvVars')]
    public function testEmptyValueFallsBackToTheDefault(string $envVar, string $parameter, int $expected): void
    {
        $previous = getenv($envVar);
        putenv($envVar.'=');
        $_ENV[$envVar] = '';

        try {
            self::bootKernel();
            $value = self::getContainer()->getParameter($parameter);
        } finally {
            self::ensureKernelShutdown();
            unset($_ENV[$envVar]);
            putenv(false === $previous ? $envVar : $envVar.'='.$previous);
        }

        self::assertSame($expected, $value, \sprintf('An empty %s must fall back to its default, not abort the container.', $envVar));
    }

    #[DataProvider('castingEnvVars')]
    public function testConfiguredValueStillWins(string $envVar, string $parameter, int $default): void
    {
        $previous = getenv($envVar);
        putenv($envVar.'=21');
        $_ENV[$envVar] = '21';

        try {
            self::bootKernel();
            $value = self::getContainer()->getParameter($parameter);
        } finally {
            self::ensureKernelShutdown();
            unset($_ENV[$envVar]);
            putenv(false === $previous ? $envVar : $envVar.'='.$previous);
        }

        self::assertSame(21, $value, 'The fallback must not swallow a value the operator did set.');
        self::assertNotSame($default, $value, 'A configured value has to differ from the default, or this test proves nothing.');
    }

    /**
     * #174: a string needs no cast, but `${EXPLOITATION_KEV_URL:-}` still arrives empty on every
     * stage that keeps cisa.gov — an empty URL would fail every KEV download.
     */
    public function testEmptyKevUrlFallsBackToCisaAndAConfiguredMirrorWins(): void
    {
        $mirror = 'https://raw.githubusercontent.com/cisagov/kev-data/develop/known_exploited_vulnerabilities.json';

        self::assertSame(
            'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json',
            $this->parameterWith('EXPLOITATION_KEV_URL', '', 'app.exploitation.kev_url'),
        );
        self::assertSame($mirror, $this->parameterWith('EXPLOITATION_KEV_URL', $mirror, 'app.exploitation.kev_url'));
    }

    private function parameterWith(string $envVar, string $value, string $parameter): mixed
    {
        $previous = getenv($envVar);
        putenv($envVar.'='.$value);
        $_ENV[$envVar] = $value;
        try {
            self::bootKernel();

            return self::getContainer()->getParameter($parameter);
        } finally {
            self::ensureKernelShutdown();
            unset($_ENV[$envVar]);
            putenv(false === $previous ? $envVar : $envVar.'='.$previous);
        }
    }
}
