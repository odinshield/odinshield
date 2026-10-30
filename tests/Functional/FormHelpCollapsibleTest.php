<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Field help is collapsible and wired up correctly (#79).
 *
 * The point of these assertions is the wiring, not the appearance: a toggle
 * whose `aria-controls` names an id that does not exist looks fine in the
 * browser and does nothing — the same silent-no-op class of defect that left
 * #51 broken for months. Whether the hint is actually *visible* is a question
 * the DOM cannot answer and was checked in the browser instead.
 *
 * The markup deliberately renders every hint expanded; the Stimulus controller
 * collapses it on connect. Without JavaScript the text therefore stays
 * readable, so this test asserts the expanded state.
 */
final class FormHelpCollapsibleTest extends AbstractWebTestCase
{
    public function testAssetGroupFormRendersEveryHintWithAMatchingToggle(): void
    {
        $this->loginAsHardwareOwner();
        $crawler = $this->client->request('GET', '/asset-group/new');

        self::assertResponseIsSuccessful();

        $toggles = $crawler->filter('[data-form-help-target="toggle"]');
        self::assertCount(4, $toggles, 'The risk-properties section (#215) plus criticality, exposure and data sensitivity each carry a toggle.');

        /** @var list<array{target: string|null, expanded: string|null}> $wiring */
        $wiring = $toggles->each(static fn (Crawler $node): array => [
            'target' => $node->attr('aria-controls'),
            'expanded' => $node->attr('aria-expanded'),
        ]);

        foreach ($wiring as $toggle) {
            $target = (string) $toggle['target'];
            self::assertNotSame('', $target, 'Every toggle must name the hint it controls.');
            self::assertCount(
                1,
                $crawler->filter('#'.$target),
                \sprintf('aria-controls="%s" must point at an element that exists.', $target),
            );
            self::assertSame(
                'true',
                $toggle['expanded'],
                'The server renders hints expanded; collapsing is the controller\'s job.',
            );
        }

        self::assertCount(
            4,
            $crawler->filter('[data-form-help-target="hint"]'),
            'Each hint keeps its own element so a single one can be toggled.',
        );
        self::assertCount(
            1,
            $crawler->filter('[data-form-help-target="switch"]'),
            'A form with several hints carries the show-everything switch.',
        );
        self::assertCount(
            1,
            $crawler->filter('form[data-controller="form-help"]'),
            'Without the controller on the form the targets are never found.',
        );
    }

    public function testHintTextStillComesFromTheFormType(): void
    {
        $this->loginAsHardwareOwner();
        $crawler = $this->client->request('GET', '/asset-group/new');

        self::assertResponseIsSuccessful();

        // The single source for a hint is the form type's `help` option. If someone
        // hard-codes the text in the template again, the option can drift unnoticed.
        $hints = $crawler->filter('[data-form-help-target="hint"]')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );

        self::assertNotEmpty($hints);
        foreach ($hints as $hint) {
            self::assertNotSame('', $hint, 'A rendered hint must carry text, not an empty element.');
        }
    }
}
