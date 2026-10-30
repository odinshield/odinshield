<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'odinshield:manual:render-static',
    description: 'Renders all manual pages to static HTML for indexing (Pagefind).',
)]
final class ManualRenderStaticCommand
{
    private const array LOCALES = ['de', 'en'];

    public function __construct(
        #[Autowire(service: 'app.markdown.manual_converter')]
        private readonly MarkdownConverter $markdownConverter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Run even when APP_ENV=prod (image-build context).')]
        bool $force = false,
    ): int {
        $fs = new Filesystem();

        // SEC-MD09: This command is intended for build/dev only. Refuse to run in a prod
        // runtime container (where docs/manual/ may be excluded or where rendered output
        // would clobber the image-baked index). The Dockerfile prod-builder passes --force.
        if ('prod' === $this->appEnv && !$force) {
            $io->error('Refusing to render manual in APP_ENV=prod without --force. Rebuild the image instead.');

            return Command::FAILURE;
        }

        $sourceDir = $this->projectDir.'/docs/manual';
        // Path is at the project root (not under var/), so host and container both see it.
        // var/ is masked by an anonymous Docker volume in dev → invisible to host-side Pagefind.
        $targetDir = $this->projectDir.'/.pagefind-build/manual';

        if (!is_dir($sourceDir)) {
            $io->error(sprintf('Source directory not found: %s', $sourceDir));

            return Command::FAILURE;
        }

        $fs->remove($targetDir);
        $fs->mkdir($targetDir);

        $count = 0;
        foreach (self::LOCALES as $locale) {
            $localeSource = $sourceDir.'/'.$locale;
            if (!is_dir($localeSource)) {
                $io->warning(sprintf('Skipping locale "%s": no source directory.', $locale));
                continue;
            }

            $localeTarget = $targetDir.'/'.$locale;
            $fs->mkdir($localeTarget);

            $finder = (new Finder())->files()->in($localeSource)->name('*.md');
            foreach ($finder as $file) {
                $slug = $file->getFilenameWithoutExtension();
                $markdown = $file->getContents();
                $bodyHtml = (string) $this->markdownConverter->convert($markdown);

                $title = $this->extractTitle($markdown) ?? $slug;
                $url = sprintf('/manual/%s', $slug);

                $html = $this->renderShell($title, $bodyHtml, $locale, $url);

                $fs->dumpFile($localeTarget.'/'.$slug.'.html', $html);
                ++$count;
            }
        }

        $io->success(sprintf('Rendered %d static manual pages to %s', $count, $targetDir));

        return Command::SUCCESS;
    }

    /**
     * Returns the first top-level heading from the Markdown, ignoring fenced
     * code blocks (where a leading "#" is part of the example, not a heading).
     * The result is capped at 200 characters to bound any pathological input.
     */
    private function extractTitle(string $markdown): ?string
    {
        $inFence = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (1 === preg_match('/^```/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if (!$inFence && 1 === preg_match('/^#\s+(.+)$/', $line, $m)) {
                return mb_substr(trim($m[1]), 0, 200);
            }
        }

        return null;
    }

    private function renderShell(string $title, string $body, string $locale, string $url): string
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $localeEsc = htmlspecialchars($locale, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Pagefind generates URLs from the on-disk file path (e.g. /de/team.html).
        // Override attempts via <meta data-pagefind-meta="url"> are not honored
        // reliably across Pagefind releases — the JS search controller normalizes
        // the URL after lookup (strips locale prefix + .html) instead.
        return <<<HTML
<!DOCTYPE html>
<html lang="{$localeEsc}">
<head>
<meta charset="UTF-8">
<title>{$titleEsc}</title>
<meta data-pagefind-meta="title" content="{$titleEsc}">
<meta data-pagefind-meta="url" content="{$urlEsc}">
</head>
<body>
<main data-pagefind-body>
{$body}
</main>
</body>
</html>

HTML;
    }
}
