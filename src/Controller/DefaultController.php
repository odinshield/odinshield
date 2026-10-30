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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-007: Explicit gate — defence-in-depth against future access_control refactoring.
// REMEMBERED, not FULLY: remember-me is honoured for everyday browsing (SEC-016, #186).
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class DefaultController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%app_version%')]
        private readonly string $appVersion,
    ) {
    }

    /**
     * The "Rechtliches" page: licence of OdinShield, licences of the third-party components,
     * and — for those allowed to see it — the SBOM of this build (#83).
     *
     * Renamed from `/license`: the page carries more than the licence now, and a page titled
     * "Rechtliches" living at `/license` is the kind of small lie someone trips over later.
     */
    #[Route('/legal', name: 'app_legal')]
    public function legal(): Response
    {
        // #217: without a built SBOM (any development checkout) the page says so instead of
        // offering a link that ends in a bare 404.
        return $this->render('default/legal.html.twig', [
            'sbom_available' => is_file($this->projectDir.'/sbom.json'),
        ]);
    }

    /**
     * Hands out the SBOM of the running artefact (#82).
     *
     * SEC-019: restricted to ROLE_ADMIN and ROLE_ISB. The document lists every component with
     * its exact version and is therefore a map of the attack surface — it does not belong
     * behind a link that every authenticated user can follow.
     *
     * The file is produced at build time (see the SBOM step in the Dockerfile). It is absent
     * in a development checkout, and that is not an error worth a 500: the answer is simply
     * that this instance has none.
     */
    #[Route('/sbom', name: 'app_sbom', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_ISB')"))]
    public function sbom(): Response
    {
        $path = $this->projectDir.'/sbom.json';

        if (!is_file($path)) {
            throw $this->createNotFoundException('No SBOM was built into this instance.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/vnd.cyclonedx+json');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('odinshield-%s-sbom.json', $this->appVersion),
        );

        return $response;
    }
}
