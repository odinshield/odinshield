<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * SEC-014: Session-expiry handling that plays well with Hotwire.
 *
 * A genuine top-level browser navigation to a protected URL is redirected to the login form
 * (302), as usual. A Turbo/fetch request (Turbo Drive visit, frame load, stream, or a raw
 * fetch() from a modal loader) instead receives a bare 401 carrying the login URL in
 * `X-Login-Redirect`. The frontend turns that into a hard redirect. This stops the login page
 * from being silently injected into a Turbo frame or a modal body once the session has expired
 * (audit H4/L16).
 */
final readonly class TurboAwareAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $loginUrl = $this->urlGenerator->generate('app_login');

        if ($this->isFetchLike($request)) {
            return new Response('', Response::HTTP_UNAUTHORIZED, ['X-Login-Redirect' => $loginUrl]);
        }

        return new RedirectResponse($loginUrl);
    }

    private function isFetchLike(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');
        if (str_contains($accept, 'text/vnd.turbo-stream.html')) {
            return true;
        }
        if ($request->headers->has('Turbo-Frame')) {
            return true;
        }
        // Modern browsers tag genuine top-level navigations as `Sec-Fetch-Mode: navigate`; Turbo
        // Drive visits and fetch()/XHR use `cors`/`same-origin`. Treat any present-but-non-navigate
        // value as fetch-like. When the header is absent (old browser) we fall back to the 302.
        $secFetchMode = $request->headers->get('Sec-Fetch-Mode');

        return null !== $secFetchMode && 'navigate' !== $secFetchMode;
    }
}
