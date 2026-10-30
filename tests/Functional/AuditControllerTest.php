<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Global audit timeline (issue #32): ROLE_ADMIN-only compliance view over all `*_audit` tables.
 * Also implicitly verifies that the auditor schema listener creates the audit tables under the
 * test SchemaTool (the UNION query would error otherwise).
 */
final class AuditControllerTest extends AbstractWebTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/audit');

        self::assertResponseRedirects('/login');
    }

    public function testIndexForbiddenForNonAdmin(): void
    {
        $this->loginAsSoftwareOwner();

        $this->client->request('GET', '/audit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexRendersForAdmin(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1.page-title');
    }

    public function testSeededEntryAppearsAndEntityTypeFilterNarrows(): void
    {
        // Creating the asset group flushes an `insert` audit row into asset_group_audit.
        $this->createAssetGroup();
        $this->loginAsAdmin();

        $this->client->request('GET', '/audit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Test Asset Group', (string) $this->client->getResponse()->getContent());

        // Same filter → still present.
        $this->client->request('GET', '/audit?entity_type=asset_group');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Test Asset Group', (string) $this->client->getResponse()->getContent());

        // Different entity type → the asset-group entry must not leak through (no vulnerability audit).
        $this->client->request('GET', '/audit?entity_type=vulnerability');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Test Asset Group', (string) $this->client->getResponse()->getContent());
    }

    /**
     * #206: the details open from a real button, reachable by keyboard; the row itself stays a plain
     * table row. A toggle whose aria-controls names a missing id would look right and do nothing.
     */
    public function testChangeDetailsOpenFromAKeyboardReachableButton(): void
    {
        // The insert of the asset group carries field diffs, so its row gets a toggle.
        $this->createAssetGroup();
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group');
        self::assertResponseIsSuccessful();

        $toggles = $crawler->filter('button.audit-toggle');
        self::assertGreaterThan(0, $toggles->count(), 'A row with diffs must carry the toggle button.');
        self::assertCount(0, $crawler->filter('tr[role], tr[data-bs-toggle]'), 'The row must not pose as a button.');

        /** @var list<array{controls: string, target: string|null, expanded: string|null, label: string}> $wiring */
        $wiring = $toggles->each(static fn (Crawler $node): array => [
            'controls' => (string) $node->attr('aria-controls'),
            'target' => $node->attr('data-bs-target'),
            'expanded' => $node->attr('aria-expanded'),
            'label' => trim((string) $node->attr('aria-label')),
        ]);

        foreach ($wiring as $toggle) {
            self::assertNotSame('', $toggle['controls']);
            self::assertSame('#'.$toggle['controls'], $toggle['target']);
            self::assertSame('false', $toggle['expanded']);
            self::assertNotSame('', $toggle['label']);
            self::assertCount(1, $crawler->filter('#'.$toggle['controls']), \sprintf('aria-controls="%s" must exist.', $toggle['controls']));
        }
    }

    /**
     * #201: the timeline names every object, but links only those the viewer may open — a plain
     * admin (no ISB/owner role) gets a 403 on an asset group's page, so no link into it.
     */
    public function testObjectIsLinkedOnlyForViewersWhoMayOpenIt(): void
    {
        $assetGroup = $this->createAssetGroup();
        $link = 'a[href="/asset-group/'.$assetGroup->getUuid().'"]';

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $plainAdmin = new \App\Entity\User();
        $plainAdmin->setUsername('plainadmin');
        $plainAdmin->setFirstName('Plain');
        $plainAdmin->setLastName('Admin');
        $plainAdmin->setEmail('plainadmin@example.com');
        $plainAdmin->setPassword('unused');
        $plainAdmin->setIsActive(true);
        $plainAdmin->setLocale('en');
        $plainAdmin->setMustChangePassword(false);
        $plainAdmin->setRoles([\App\Entity\User::ROLE_ADMIN]);
        $em->persist($plainAdmin);
        $em->flush();

        $this->client->loginUser($plainAdmin);
        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Test Asset Group', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter($link));

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/audit?entity_type=asset_group');
        self::assertGreaterThan(0, $crawler->filter($link)->count());
    }

    public function testActionFilterNarrows(): void
    {
        $this->createAssetGroup();
        $this->loginAsAdmin();

        // Only inserts have happened → the insert filter shows the entry …
        $this->client->request('GET', '/audit?action=insert');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Test Asset Group', (string) $this->client->getResponse()->getContent());

        // … while the remove filter yields nothing for it.
        $this->client->request('GET', '/audit?action=remove');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Test Asset Group', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidFilterParamsAreIgnored(): void
    {
        $this->loginAsAdmin();

        // Bogus allow-list values must not blow up (they reset to defaults).
        $this->client->request('GET', '/audit?action=DROP&entity_type=..%2F&user=ghost&limit=999&date_from=nope');

        self::assertResponseIsSuccessful();
    }
}
