<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\UserGroup;
use App\Entity\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Screen-reader wiring of forms and list controls (#206).
 *
 * The forms are hand-built from form_widget() calls, so the global form theme
 * (templates/form/a11y_layout.html.twig) links each widget to its hint and its
 * errors. A reference to an id that does not exist is silent in the browser —
 * these tests check that every reference lands.
 */
final class AccessibilityWiringTest extends AbstractWebTestCase
{
    public function testEveryDescribedByReferenceExists(): void
    {
        $this->loginAsAdmin();
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetGroup = $this->createAssetGroup();
        $vendor = (new Vendor())->setName('Acme')->setVendorKey('acme');
        $userGroup = new UserGroup();
        $userGroup->setName('Team');
        $em->persist($vendor);
        $em->persist($userGroup);
        $em->flush();

        $urls = [
            '/asset-group/new', '/asset-group/'.$assetGroup->getUuid().'/edit',
            '/asset-type/new', '/asset-type/'.$assetGroup->getAssetType()->getUuid().'/edit',
            '/user/new',
            '/user-group/new', '/user-group/'.$userGroup->getUuid().'/edit',
            '/vendor/new', '/vendor/'.$vendor->getUuid().'/edit',
            '/software/new',
            '/vulnerability/new',
            '/settings',
        ];

        $dangling = [];
        $described = 0;
        foreach ($urls as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            /** @var list<array{id: string|null, describedby: string|null}> $fields */
            $fields = $crawler->filter('[aria-describedby]')->each(static fn (Crawler $node): array => [
                'id' => $node->attr('id'),
                'describedby' => $node->attr('aria-describedby'),
            ]);

            foreach ($fields as $field) {
                ++$described;
                $ids = preg_split('/\s+/', trim((string) $field['describedby']), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($ids as $id) {
                    if (0 === $crawler->filter('#'.$id)->count()) {
                        $dangling[] = \sprintf('%s: #%s -> missing #%s', $url, $field['id'], $id);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $described, 'Fields with a hint must reference it.');
        self::assertSame([], $dangling);
    }

    /**
     * #323: the key fields link to the NVD CPE Search in a new tab, and say so to screen readers.
     */
    public function testKeyFieldsLinkToNvdCpeSearch(): void
    {
        $this->loginAsAdmin();

        // The software form embeds the vendor quick-create modal, hence two links there.
        foreach (['/vendor/new' => 1, '/software/new' => 2] as $url => $expected) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            $links = $crawler->filter('a[href="https://nvd.nist.gov/products/cpe/search"]');
            self::assertCount($expected, $links, $url);
            $links->each(static function (Crawler $link) use ($url): void {
                self::assertSame('_blank', $link->attr('target'), $url);
                self::assertSame('noopener noreferrer', $link->attr('rel'), $url);
                self::assertSame('(opens in a new tab)', $link->filter('.visually-hidden')->text(), $url);
            });
        }
    }

    public function testInvalidFieldIsMarkedAndPointsAtItsErrors(): void
    {
        $this->loginAsAdmin();
        $this->createAssetGroup();

        $crawler = $this->client->request('GET', '/asset-group/new');
        $values = $crawler->filter('form[method="post"]:not([action="/logout"])')->first()->form()->getPhpValues();
        $values['asset_group']['name'] = 'Named, but untyped';
        $values['asset_group']['assetType'] = '';
        $crawler = $this->client->request('POST', '/asset-group/new', $values);

        self::assertResponseStatusCodeSame(422);

        $field = $crawler->filter('#asset_group_assetType');
        self::assertSame('true', $field->attr('aria-invalid'));
        self::assertContains('asset_group_assetType_errors', explode(' ', (string) $field->attr('aria-describedby')));
        self::assertStringContainsString('Asset type is required.', $crawler->filter('#asset_group_assetType_errors')->text());

        $valid = $crawler->filter('#asset_group_name');
        self::assertNull($valid->attr('aria-invalid'), 'A valid field is not marked invalid.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listProvider(): iterable
    {
        foreach (['/asset-group', '/asset-type', '/audit', '/software', '/user', '/user-group', '/vendor', '/vulnerability'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('listProvider')]
    public function testListControlsHaveAnAccessibleName(string $url): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $controls = ['select[name="limit"]'];
        // The audit timeline filters by dropdowns only; it has no free-text search.
        if ('/audit' !== $url) {
            $controls = [...$controls, '#search-input', '#search-clear', '.input-group button[type="submit"]'];
        }

        foreach ($controls as $selector) {
            $node = $crawler->filter($selector);
            self::assertCount(1, $node, \sprintf('%s on %s', $selector, $url));
            self::assertNotSame('', trim((string) $node->attr('aria-label')), \sprintf('%s on %s needs a name', $selector, $url));
        }

        $unnamed = $crawler->filter('td .dropdown-toggle')->reduce(
            static fn (Crawler $toggle): bool => '' === trim((string) $toggle->attr('aria-label')),
        );
        self::assertCount(0, $unnamed, 'Every row action menu needs a name.');
    }
}
