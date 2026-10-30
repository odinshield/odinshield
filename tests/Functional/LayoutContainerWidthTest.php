<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The table lists grow with the window, every other page stays capped (#218, variant C).
 *
 * `container-xl` stops at 1,320 px, which left the lists with empty space on wide screens while
 * #171/#181 hid columns. Forms and detail pages would become unreadably wide without the cap, so
 * only the eight lists switch to `container-fluid`. Navbar, page header, body and footer share
 * one container class per page, otherwise their right edges drift apart.
 */
final class LayoutContainerWidthTest extends AbstractWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fluidPages(): iterable
    {
        yield 'vulnerabilities' => ['/vulnerability'];
        yield 'software' => ['/software'];
        yield 'asset groups' => ['/asset-group'];
        yield 'asset types' => ['/asset-type'];
        yield 'vendors' => ['/vendor'];
        yield 'users' => ['/user'];
        yield 'user groups' => ['/user-group'];
        yield 'audit' => ['/audit'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cappedPages(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'import status' => ['/import-status'];
        yield 'settings' => ['/settings'];
        yield 'vulnerability form' => ['/vulnerability/new'];
    }

    #[DataProvider('fluidPages')]
    public function testListGrowsWithTheWindow(string $path): void
    {
        $this->assertContainerClass($path, 'container-fluid');
    }

    #[DataProvider('cappedPages')]
    public function testOtherPagesStayCapped(string $path): void
    {
        $this->assertContainerClass($path, 'container-xl');
    }

    private function assertContainerClass(string $path, string $expected): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        foreach (['header.navbar', '.page-body', 'footer.footer'] as $region) {
            self::assertSame(
                $expected,
                $crawler->filter($region.' > div')->first()->attr('class'),
                \sprintf('%s on %s', $region, $path),
            );
        }

        // Pages without a page header (none today) would make this filter empty — check it only where present.
        $header = $crawler->filter('.page-header > div');
        if ($header->count() > 0) {
            self::assertSame($expected, $header->first()->attr('class'), \sprintf('.page-header on %s', $path));
        }
    }
}
