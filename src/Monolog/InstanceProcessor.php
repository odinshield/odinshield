<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Monolog;

use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the sending instance onto the records of the prod error mail (#144).
 *
 * Q, Demo and the customer test run the same image with the same config and may
 * report to the same recipient, and the subject used to read `[OdinShield prod]`
 * everywhere — `prod` is the Symfony environment, not the instance. The value
 * lands in `extra.instance`, which the mail subject references and the HTML
 * formatter prints in the body.
 *
 * Derived from `DEFAULT_URI` because compose.deploy.yaml already passes it to
 * both `php` and `worker` and it is unique per stage; no new deploy variable. It
 * is read from the environment, not from a request, so worker and console
 * errors — which have no request — are named too.
 *
 * The host is parsed here rather than with `%env(key:host:url:DEFAULT_URI)%`:
 * that processor throws on a malformed value, and a logger that cannot be built
 * takes the very error report down with it.
 *
 * Deliberately neither `ProcessorInterface` nor `#[AsMonologProcessor]`: the
 * bundle would autoconfigure the former onto every handler in every
 * environment, and the latter cannot name `symfony_mailer`, a handler that only
 * exists in prod. It is bound in config/services.yaml under `when@prod`.
 */
final readonly class InstanceProcessor
{
    private const string UNKNOWN = 'unknown';

    private string $instance;

    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        string $defaultUri,
    ) {
        $this->instance = self::instanceFrom($defaultUri);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'instance' => $this->instance]);
    }

    private static function instanceFrom(string $defaultUri): string
    {
        $defaultUri = trim($defaultUri);
        $host = parse_url($defaultUri, \PHP_URL_HOST);

        if (\is_string($host) && '' !== $host) {
            $port = parse_url($defaultUri, \PHP_URL_PORT);

            return \is_int($port) ? $host.':'.$port : $host;
        }

        // A bare host without scheme parses as a path; still better than nothing.
        return '' !== $defaultUri ? $defaultUri : self::UNKNOWN;
    }
}
