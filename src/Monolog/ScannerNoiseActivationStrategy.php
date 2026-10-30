<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Monolog;

use Monolog\Handler\FingersCrossed\ActivationStrategyInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * FingersCrossed activation strategy for the prod error pipeline (stderr + mail).
 *
 * Fires on error+ EXCEPT for client-induced noise that never indicates a server
 * bug — the server already returned the correct status, so alerting adds nothing
 * but drowns out real signals during a scan (see PENTEST/ for the burst that
 * motivated this):
 *
 *  - HTTP error responses with a status in {@see self::DEFAULT_EXCLUDED_HTTP_CODES}
 *    (400 malformed body, 404 unknown route, 405 wrong method).
 *  - Specific PHP warnings raised while PHP/FrankenPHP parses attacker-controlled
 *    input (missing multipart boundary, malformed session id). These surface on
 *    the "php" channel as an ErrorException at ERROR level and carry NO HTTP
 *    status, so {@see \Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy}
 *    cannot see them at all.
 *
 * Unlike that stock strategy this reads the status code straight off the
 * exception and never touches RequestStack: under FrankenPHP worker mode
 * getMainRequest() is not reliably populated when the handler evaluates a
 * record, which let 404s through to the mailer during the security scan despite
 * `excluded_http_codes` being configured.
 *
 * NOT excluded on purpose: 403 (AccessDenied) from a real navigation. An authenticated user
 * hitting a forbidden route is worth seeing — it flags either a misconfiguration or someone
 * probing authorization. CrowdSec absorbs the volume at the edge in prod.
 *
 * Excluded since #77: 401/403 raised by a Turbo PREFETCH. Turbo 8 prefetches links on hover, so
 * a stale page (session switched in the same tab, role changed mid-session, back button onto a
 * cached view) can fire a request at a forbidden route that nobody clicked — Turbo discards the
 * failed prefetch silently, leaving the operator with a mail about a URL the user never visited.
 * The prefetch mark reaches this class in `$record->extra['prefetch']`, stamped by
 * {@see PrefetchProcessor} while the request is still on the stack; reading the header here
 * would mean reintroducing the RequestStack dependency this class exists to avoid. Only those
 * two status codes are dropped: a 500 during a prefetch is still a server bug and still alerts.
 */
final class ScannerNoiseActivationStrategy implements ActivationStrategyInterface
{
    /**
     * @var list<int>
     */
    public const array DEFAULT_EXCLUDED_HTTP_CODES = [400, 404, 405];

    /**
     * Statuses that are noise ONLY when the request was a prefetch: the user neither triggered
     * nor saw them. Kept deliberately short — anything a prefetch can surface that would also be
     * a bug during a real navigation must stay visible.
     *
     * @var list<int>
     */
    public const array PREFETCH_EXCLUDED_HTTP_CODES = [401, 403];

    /**
     * Regexes matched against the message of "php"-channel records. Each pattern
     * targets a warning whose trigger is 100 % client-controlled input, so it can
     * never indicate a server bug. Keep this list tight — broad matches would
     * silence genuine code warnings (e.g. "Undefined array key").
     *
     * @var list<non-empty-string>
     */
    private const array BENIGN_PHP_WARNINGS = [
        '/frankenphp_handle_request\(\): Missing boundary in multipart\/form-data/',
        '/SessionHandler::\w+\(\): Session ID is too long or contains illegal characters/',
    ];

    /**
     * @param list<int> $excludedHttpCodes
     */
    public function __construct(
        private readonly Level $actionLevel = Level::Error,
        private readonly array $excludedHttpCodes = self::DEFAULT_EXCLUDED_HTTP_CODES,
    ) {
    }

    public function isHandlerActivated(LogRecord $record): bool
    {
        if ($record->level->value < $this->actionLevel->value) {
            return false;
        }

        $exception = $record->context['exception'] ?? null;

        if (
            $exception instanceof HttpExceptionInterface
            && \in_array($exception->getStatusCode(), $this->excludedHttpCodes, true)
        ) {
            return false;
        }

        if (
            $exception instanceof HttpExceptionInterface
            && true === ($record->extra['prefetch'] ?? false)
            && \in_array($exception->getStatusCode(), self::PREFETCH_EXCLUDED_HTTP_CODES, true)
        ) {
            return false;
        }

        if ('php' === $record->channel && $this->isBenignPhpWarning($record->message)) {
            return false;
        }

        return true;
    }

    private function isBenignPhpWarning(string $message): bool
    {
        foreach (self::BENIGN_PHP_WARNINGS as $pattern) {
            if (1 === preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}
