<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\CsafDocumentRecord;
use App\Entity\CsafProviderState;
use App\Entity\ExploitationEnrichmentState;
use App\Entity\User;
use App\Enum\CsafDocumentOutcome;
use App\Enum\CsafProviderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Combined CSAF + exploitation-enrichment status (issue #43, #134).
 *
 * The role gate is the load-bearing part: this page moved off the dashboard and is ROLE_ADMIN only
 * by design — ROLE_ISB deliberately lost the view. testForbiddenForIsb() guards that decision, so a
 * failure there means someone widened the gate, not that the test is stale.
 */
final class ImportStatusControllerTest extends AbstractWebTestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/import-status');

        self::assertResponseRedirects('/login');
    }

    public function testForbiddenForIsb(): void
    {
        $this->loginAsIsb();

        $this->client->request('GET', '/import-status');

        self::assertResponseStatusCodeSame(403);
    }

    public function testForbiddenForSoftwareOwner(): void
    {
        $this->loginAsSoftwareOwner();

        $this->client->request('GET', '/import-status');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRendersBothSourcesForAdmin(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->persist(new CsafProviderState('test-provider', 'Test Provider', 'https://example.test/provider-metadata.json'));
        $em->flush();

        $this->loginAsAdmin();

        $this->client->request('GET', '/import-status');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1.page-title');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Test Provider', $content);
        // The enricher is listed even though it has never run — an unscheduled source must be visible.
        self::assertStringContainsString('FIRST EPSS / CISA KEV', $content);
    }

    /**
     * #208: the enrichment row is always listed, so the page is never empty — but without any CSAF
     * provider an admin has to learn how one is added.
     */
    public function testHintsAtProviderAddWhenNoCsafSourceExists(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/import-status');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('odinshield:csaf:provider-add', $crawler->filter('tbody')->text());
    }

    /**
     * #208: the full error text used to live only in a title tooltip, which a tablet never shows. It
     * is now the summary of a disclosure element — complete in the DOM, wrapped when opened.
     */
    public function testLastErrorIsRenderedInFullAsDisclosure(): void
    {
        $message = str_repeat('Feed https://example.test/very/long/path returned HTTP 503. ', 5);

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $state = new CsafProviderState('test-provider', 'Test Provider', 'https://example.test/provider-metadata.json');
        $state->setLastErrorMessage($message);
        $state->setAdvisoriesImportedTotal(12345);
        $em->persist($state);
        $em->flush();

        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/import-status');

        self::assertResponseIsSuccessful();
        self::assertSame(trim($message), $crawler->filter('details.import-error > summary')->text());
        self::assertCount(0, $crawler->filter('tbody [title]'), 'no tooltip-only text left');
        self::assertStringNotContainsString('provider-add', $crawler->filter('tbody')->text(), 'no hint once a provider exists');
        self::assertStringContainsString('12,345', $crawler->filter('tbody')->text(), 'locale-aware count');
    }

    /**
     * #319: the read volume from the document ledger — what the import read, what is still
     * pending, failing or given up — with the given-up documents named, never a silent give-up.
     */
    public function testReadVolumeFromTheLedgerIsShown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $state = new CsafProviderState('test-provider', 'Test Provider', 'https://example.test/provider-metadata.json');
        $state->setRecentFeedEntries(15);
        $state->setPendingDocuments(3);
        $em->persist($state);
        $em->persist(CsafDocumentRecord::handled('test-provider', 'https://example.test/a.json', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable(), CsafDocumentOutcome::Processed));
        $em->persist(CsafDocumentRecord::handled('test-provider', 'https://example.test/b.json', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable(), CsafDocumentOutcome::Rebuild));
        $em->persist(CsafDocumentRecord::failed('test-provider', 'https://example.test/c.json', new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'), 'HTTP 503'));
        $stamp = new \DateTimeImmutable('-40 days');
        $givenUp = CsafDocumentRecord::failed('test-provider', 'https://example.test/broken.json', $stamp, $stamp, 'Parse failed: not JSON');
        for ($i = 0; $i < 4; ++$i) {
            $givenUp->recordFailure($stamp, new \DateTimeImmutable('-30 days'), 'Parse failed: not JSON');
        }
        $em->persist($givenUp);
        $em->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/import-status');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('tbody')->text();
        self::assertStringContainsString('3 read, 1 of them evaluated', $text);
        self::assertStringContainsString('15 listed in the feed', $text);
        self::assertStringContainsString('3 pending after the last run', $text);
        self::assertStringContainsString('1 failed, will be retried', $text);
        self::assertStringContainsString('1 given up', $text);
        self::assertStringContainsString('https://example.test/broken.json', $text);
        self::assertStringContainsString('Parse failed: not JSON', $text);
        self::assertStringNotContainsString('Reads nothing', $text);
    }

    /**
     * #319 health rule, the shape of #313: every run succeeded, the feed listed entries, the import
     * read none of them. A disabled provider is not flagged — it reads nothing on purpose.
     */
    public function testProviderThatReadsNothingIsFlagged(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $silent = new CsafProviderState('silent-provider', 'Silent Provider', 'https://example.test/provider-metadata.json');
        $silent->setRecentFeedEntries(40);
        $em->persist($silent);
        $disabled = new CsafProviderState('off-provider', 'Off Provider', 'https://example.test/off.json');
        $disabled->setRecentFeedEntries(40);
        $disabled->setStatus(CsafProviderStatus::Disabled);
        $em->persist($disabled);
        $em->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/import-status');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody .badge:contains("Reads nothing")'));
        self::assertStringContainsString('Silent Provider', $crawler->filter('tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Reads nothing'))->text());
    }

    /**
     * A GET must not write. The enricher's state row is created lazily via getOrCreate();
     * the status page uses findState() and has to tolerate its absence.
     */
    public function testRenderingDoesNotCreateEnrichmentState(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/import-status');
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();

        self::assertNull(
            $em->getRepository(ExploitationEnrichmentState::class)->find(ExploitationEnrichmentState::ID),
            'Rendering the import-status page must not persist an ExploitationEnrichmentState row.',
        );
    }

    public function testDashboardNoLongerShowsCsafStatus(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->persist(new CsafProviderState('test-provider', 'Test Provider', 'https://example.test/provider-metadata.json'));
        $em->flush();

        $this->loginAsIsb();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Test Provider', (string) $this->client->getResponse()->getContent());
    }

    /**
     * ISB without admin — loginAsAdmin() grants every role, so it cannot express this distinction.
     */
    private function loginAsIsb(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('testisb');
        $user->setFirstName('Test');
        $user->setLastName('Isb');
        $user->setEmail('testisb@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_ISB]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        return $user;
    }
}
