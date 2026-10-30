<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Streams Pagefind index assets (under /pagefind/*) only to authenticated users.
 *
 * Without this controller the index — which contains the full text of every
 * manual chapter in compressed form — would be reachable unauthenticated via
 * Caddy's static file_server (SEC-MD02). Caddyfile rewrites every /pagefind/*
 * request to index.php so this route always wins, regardless of file existence.
 */
// SEC-MD02 / SEC-007: Defence-in-depth gate alongside access_control. REMEMBERED like the
// manual itself (SEC-016, #186) — the search must work wherever the manual does.
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PagefindAssetController extends AbstractController
{
    /**
     * MIME types for known Pagefind output extensions. Auto-detection via finfo
     * gets these wrong (especially .pagefind / .pf_* binary blobs).
     */
    private const array CONTENT_TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'wasm' => 'application/wasm',
        'pagefind' => 'application/wasm',
        'pf_meta' => 'application/octet-stream',
        'pf_index' => 'application/octet-stream',
        'pf_fragment' => 'application/octet-stream',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(
        path: '/pagefind/{path}',
        name: 'app_pagefind_asset',
        requirements: ['path' => '.+'],
        methods: ['GET', 'HEAD'],
    )]
    public function asset(string $path): Response
    {
        // Path-traversal defence: forbid any path containing parent-dir traversal,
        // backslashes, or null bytes. The Caddy rewrite already strips fragments and
        // resolves slashes, but defence in depth.
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw $this->createNotFoundException();
        }

        $base = $this->projectDir.'/public/pagefind';
        $full = $base.'/'.$path;

        if (!is_file($full)) {
            throw $this->createNotFoundException();
        }

        // Symlink / realpath defence: ensure the resolved file actually lives under
        // public/pagefind/ — not e.g. behind a symlink pointing to /etc/passwd.
        $realBase = realpath($base);
        $realFull = realpath($full);
        if (false === $realBase || false === $realFull || !str_starts_with($realFull, $realBase.\DIRECTORY_SEPARATOR)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($realFull);

        $ext = strtolower((string) pathinfo($realFull, \PATHINFO_EXTENSION));
        if (isset(self::CONTENT_TYPES[$ext])) {
            $response->headers->set('Content-Type', self::CONTENT_TYPES[$ext]);
        } else {
            // Unknown extension — refuse rather than serving with a guessed type
            // that browsers may MIME-sniff into something dangerous.
            throw $this->createNotFoundException();
        }

        // The Pagefind index is content-addressed (filenames carry hashes), so it is safe
        // and beneficial to cache aggressively per-user. Mark private so a shared proxy
        // never serves the index to a different (unauthenticated) user.
        $response->setPrivate();
        $response->setMaxAge(300);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
