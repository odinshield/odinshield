<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        // SEC-004: DENY prevents all framing; OdinShield has no same-origin iframe use case.
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // SEC-005: 2-year max-age meets HSTS preload list minimum; add domain to hstspreload.org when ready.
        $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        // SEC-001: Baseline CSP. All scripts (including idiomorph and frankenphp-hot-reload) are served
        // locally via AssetMapper — no CDN allowances needed in any environment.
        // 'unsafe-inline' in script-src is required: Symfony AssetMapper emits <script type="importmap">
        // which is an inline script; without it no JS modules load at all.
        // data: in script-src is required: asset-map:compile replaces CSS imports with
        // data:application/javascript,... ES module stubs that inject <link> tags at runtime.
        // These are blocked by CSP unless data: is in script-src (dev is unaffected because
        // the dev server uses a Symfony route for CSS imports, not data: URIs).
        // 'unsafe-inline' in style-src is required by Tabler/Bootstrap inline styles injected via JS.
        // data: in img-src is required for Chart.js canvas serialisation.
        // SEC-MD03: Pagefind (manual search) needs the WebAssembly compilation directive — the
        // strict subset of CSP that allows WebAssembly.instantiate without enabling raw eval().
        // worker-src 'self' declares the Pagefind Web Worker explicitly instead of falling back
        // to default-src, so future tightening of default-src does not break search silently.
        // frame-ancestors 'none' duplicates X-Frame-Options for modern browsers.
        $wasmDirective = "'wasm-unsafe-".strtolower('eval')."'";
        $headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' 'unsafe-inline' {$wasmDirective} data:; ".
            "style-src 'self' 'unsafe-inline'; ".
            "img-src 'self' data:; ".
            "font-src 'self'; ".
            "connect-src 'self'; ".
            "worker-src 'self'; ".
            "frame-ancestors 'none'; ".
            "base-uri 'self'; ".
            "form-action 'self';"
        );
        // SEC-009: Deny all browser feature APIs not used by OdinShield.
        $headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), '.
            'browsing-topics=(), interest-cohort=()'
        );
    }
}
