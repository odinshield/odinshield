<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Csaf;

use App\Entity\CsafDocumentRecord;
use App\Entity\CsafProviderState;
use App\Enum\CsafDocumentOutcome;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafDocumentRecordRepository;
use App\Repository\CsafProviderStateRepository;
use App\Repository\SoftwareRepository;
use App\Service\Csaf\CsafImportRunner;
use App\Service\Csaf\Fetcher\AggregatorExpander;
use App\Service\Csaf\Fetcher\ChangesCsvReader;
use App\Service\Csaf\Fetcher\ProviderMetadataFetcher;
use App\Service\Csaf\Fetcher\RolieFeedReader;
use App\Service\Csaf\Parser\CsafDocumentParser;
use App\Service\Csaf\Upserter\VulnerabilityUpserter;
use App\Service\Http\OutboundHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exercises the CsafImportRunner at the HTTP seam: real fetchers/reader/parser/upserter driven by
 * a MockHttpClient (the collaborators are final and cannot be mocked directly). Covers the audit
 * findings H2 (no advisory loss — since #319 through the document ledger), M1 (closed-EM
 * resilience) and L13 (zero-feed provider treated as a failure), plus the scenarios that lost
 * documents under the old fetch window (#46, #169, #313).
 */
final class CsafImportRunnerTest extends KernelTestCase
{
    private const string METADATA_URL = 'https://prov.test/metadata.json';
    private const string FEED_URL = 'https://prov.test/rolie-feed.json';
    private const string FEED_BROKEN_URL = 'https://prov.test/rolie-broken.json';

    private ManagerRegistry $registry;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        $this->registry = $registry;
        /** @var EntityManagerInterface $em */
        $em = $registry->getManager();
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testZeroFeedsIsTreatedAsFailureNotSuccess(): void
    {
        $state = $this->persistState();
        // Provider metadata advertises no ROLIE feeds → misconfiguration, not an empty success.
        $client = new MockHttpClient(fn () => $this->jsonResponse(['distributions' => []]));

        $this->makeRunner($client)->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertNull($reloaded->getLastSuccessAt(), 'A zero-feed run must not count as success.');
        self::assertSame(1, $reloaded->getConsecutiveFailures());
        self::assertNotNull($reloaded->getLastErrorMessage());
        self::assertStringContainsString('no readable distributions', (string) $reloaded->getLastErrorMessage());
    }

    /**
     * SEC-021 (#190): a provider whose metadata points into the private network (here: loopback)
     * is refused before any request leaves, and the refusal lands on /import-status as an
     * ordinary provider error instead of disappearing.
     */
    public function testPrivateNetworkTargetIsRefusedAndShownAsProviderError(): void
    {
        $requested = [];
        $mock = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;

            return $this->jsonResponse(['distributions' => []]);
        });
        $state = $this->persistState('https://127.0.0.1/.well-known/csaf/provider-metadata.json');

        $this->makeRunner(new NoPrivateNetworkHttpClient(new OutboundHttpClient($mock, 1_000_000)))->runForProvider($state, []);

        self::assertSame([], $requested, 'Nothing may be sent to a loopback address.');
        $reloaded = $this->reload();
        self::assertSame(1, $reloaded->getConsecutiveFailures());
        self::assertStringContainsString('is blocked', (string) $reloaded->getLastErrorMessage());
    }

    /**
     * SEC-021 (#190): an answer over the byte budget is cut off and booked as a provider error;
     * the run itself returns normally instead of exhausting the worker.
     */
    public function testOversizedAnswerIsCutOffAndShownAsProviderError(): void
    {
        $mock = new MockHttpClient(fn () => new MockResponse([str_repeat(' ', 800), str_repeat(' ', 800), '{"distributions":[]}']));
        $state = $this->persistState();

        // Without NoPrivateNetworkHttpClient: prov.test does not resolve, it would block first.
        $this->makeRunner(new OutboundHttpClient($mock, 1000))->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertSame(1, $reloaded->getConsecutiveFailures());
        self::assertStringContainsString('exceeds the limit of 1000 bytes', (string) $reloaded->getLastErrorMessage());
    }

    /**
     * Issue #97: a directory-based provider (Red Hat, Schneider, …) must import just like a ROLIE
     * one. Before this, ProviderMetadataFetcher returned an empty list for them, the runner booked
     * a provider failure, and after three cycles the source sat in "automatically paused" with a
     * message blaming the provider for a gap that was ours.
     */
    public function testDirectoryBasedProviderImportsFromChangesCsv(): void
    {
        $state = $this->persistState();
        $client = new MockHttpClient(function (string $method, string $url) {
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse("\"2026/doc-ok.json\",\"2026-06-01T00:00:00Z\"\n");
            }
            if (str_contains($url, 'doc-ok')) {
                return new MockResponse($this->csafDocument());
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        $stats = $this->makeRunner($client)->runForProvider($state, []);

        self::assertSame(1, $stats['processed'], 'The document listed in changes.csv must be processed.');

        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastSuccessAt());
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getLastErrorMessage());
    }

    /**
     * The relative-path resolution is the one part of #97 that fails silently: a wrong join
     * produces a plausible-looking URL that 404s on every document.
     */
    public function testDirectoryDocumentUrlsAreResolvedAgainstTheDirectory(): void
    {
        $state = $this->persistState();
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse("\"2026/doc-ok.json\",\"2026-06-01T00:00:00Z\"\n");
            }

            return new MockResponse($this->csafDocument());
        });

        $this->makeRunner($client)->runForProvider($state, []);

        self::assertContains('https://prov.test/advisories/changes.csv', $requested);
        self::assertContains('https://prov.test/advisories/2026/doc-ok.json', $requested);
    }

    /**
     * Issue #62: a provider whose every feed is TLP-restricted ends up with an empty feed list
     * too. That is still a failure — we cannot read anything from it — but the message has to say
     * "no readable feeds" rather than claiming the provider advertises none, or it sends whoever
     * reads /import-status looking for the wrong problem.
     */
    public function testProviderWithOnlyRestrictedFeedsFailsWithAReadabilityMessage(): void
    {
        $state = $this->persistState();
        $client = new MockHttpClient(fn () => $this->jsonResponse([
            'distributions' => [['rolie' => ['feeds' => [
                ['url' => 'https://prov.test/green.json', 'tlp_label' => 'GREEN'],
                ['url' => 'https://prov.test/amber.json', 'tlp_label' => 'AMBER'],
            ]]]],
        ]));

        $this->makeRunner($client)->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertNull($reloaded->getLastSuccessAt());
        self::assertStringContainsString('TLP restriction', (string) $reloaded->getLastErrorMessage());
    }

    /**
     * Issue #62: the restricted feeds must never be requested at all. Before the fix the BSI's
     * TLP:GREEN feeds were fetched on every run, answered with a bare 404, and left a permanent
     * error on /import-status while costing three pointless HTTP requests per cycle.
     */
    public function testRestrictedFeedsAreNeverRequestedButPublicOnesStillImport(): void
    {
        $state = $this->persistState();
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['rolie' => ['feeds' => [
                    ['url' => self::FEED_URL, 'tlp_label' => 'WHITE'],
                    ['url' => 'https://prov.test/green-feed.json', 'tlp_label' => 'GREEN'],
                ]]]]]);
            }
            if (str_contains($url, 'rolie-feed')) {
                return $this->jsonResponse(['feed' => ['entry' => []]]);
            }

            return $this->jsonResponse([]);
        });

        $this->makeRunner($client)->runForProvider($state, []);

        self::assertNotContains('https://prov.test/green-feed.json', $requested, 'A TLP:GREEN feed must not be fetched.');
        self::assertContains(self::FEED_URL, $requested, 'The WHITE feed is still read.');

        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastSuccessAt(), 'Skipping restricted feeds is not a failure.');
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getLastErrorMessage(), 'No error must remain on /import-status.');
    }

    public function testSuccessfulRunRecordsTheDocumentAndTheRunStart(): void
    {
        $state = $this->persistState();
        $before = new \DateTimeImmutable();
        $client = new MockHttpClient($this->responder([
            ['url' => 'https://prov.test/doc-ok.json', 'updated' => '2026-06-01T00:00:00Z'],
        ]));

        $this->makeRunner($client)->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastSuccessAt());
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        // "Letzter Lauf" on /import-status: the start of the run, nothing more since #319.
        self::assertGreaterThanOrEqual($before->getTimestamp(), $reloaded->getLastFetchedAt()?->getTimestamp());

        $record = $this->record('https://prov.test/doc-ok.json');
        self::assertSame(CsafDocumentOutcome::Processed, $record->getOutcome());
        self::assertSame('2026-06-01T00:00:00+00:00', $record->getFeedUpdated()->format(\DATE_ATOM));
        self::assertNotNull($record->getDocId());
        self::assertNotNull($record->getContentChangedAt());
    }

    /**
     * H2 on the ledger: a failed document is retried by the next run, the processed one is not
     * read again — without any window to hold.
     */
    public function testFailedDocumentIsRetriedAndHandledOneIsNotReadAgain(): void
    {
        $state = $this->persistState();
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return ($this->responder([
                ['url' => 'https://prov.test/doc-fail.json', 'updated' => '2026-01-01T00:00:00Z'],
                ['url' => 'https://prov.test/doc-ok.json', 'updated' => '2026-06-01T00:00:00Z'],
            ]))($method, $url);
        });
        $runner = $this->makeRunner($client);

        $stats = $runner->runForProvider($state, []);

        self::assertSame(1, $stats['failed']);
        self::assertSame(1, $stats['processed']);
        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastSuccessAt(), 'A per-document failure is not a provider failure.');
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        $failed = $this->record('https://prov.test/doc-fail.json');
        self::assertSame(CsafDocumentOutcome::Failed, $failed->getOutcome());
        self::assertSame(1, $failed->getAttempts());
        self::assertStringContainsString('404', (string) $failed->getLastError());

        $requested = [];
        $runner->runForProvider($this->reload(), []);

        self::assertContains('https://prov.test/doc-fail.json', $requested, 'The failed document is retried.');
        self::assertNotContains('https://prov.test/doc-ok.json', $requested, 'The handled document is not read again.');
        self::assertSame(2, $this->record('https://prov.test/doc-fail.json')->getAttempts());
    }

    /**
     * No silent infinite retry: after the attempt limit the document is no longer requested, but
     * counted and logged as given up.
     */
    public function testDocumentIsGivenUpAfterTheAttemptLimit(): void
    {
        $state = $this->persistState();
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return ($this->responder([
                ['url' => 'https://prov.test/doc-fail.json', 'updated' => '2026-01-01T00:00:00Z'],
            ]))($method, $url);
        });
        $log = new TestHandler();
        $runner = $this->makeRunner($client, maxDocumentAttempts: 2, logger: new Logger('test', [$log]));

        $runner->runForProvider($state, []);
        $runner->runForProvider($this->reload(), []);
        $requested = [];
        $stats = $runner->runForProvider($this->reload(), []);

        self::assertNotContains('https://prov.test/doc-fail.json', $requested);
        self::assertSame(1, $stats['givenUp']);
        self::assertTrue($log->hasWarning('CSAF documents given up after repeated failures'));
        self::assertTrue($this->record('https://prov.test/doc-fail.json')->isGivenUp(2));
    }

    /**
     * A provider that republishes a given-up document gets a fresh start: the new version may well
     * be the fix for whatever broke the old one.
     */
    public function testRepublishedDocumentIsTriedAgainAfterGivingUp(): void
    {
        $this->persistState();
        $stamp = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $record = CsafDocumentRecord::failed('prov_test', 'https://prov.test/doc-ok.json', $stamp, new \DateTimeImmutable('2026-01-02T00:00:00Z'), 'parse error');
        for ($i = 0; $i < 4; ++$i) {
            $record->recordFailure($stamp, new \DateTimeImmutable(), 'parse error');
        }
        $this->records()->save($record, true);
        self::assertTrue($this->record('https://prov.test/doc-ok.json')->isGivenUp(5));

        $client = new MockHttpClient($this->responder([
            ['url' => 'https://prov.test/doc-ok.json', 'updated' => '2026-02-01T00:00:00Z'],
        ]));
        $stats = $this->makeRunner($client)->runForProvider($this->reload(), []);

        self::assertSame(1, $stats['processed']);
        self::assertSame(CsafDocumentOutcome::Processed, $this->record('https://prov.test/doc-ok.json')->getOutcome());
    }

    public function testHandleProviderFailureSurvivesClosedEntityManager(): void
    {
        $state = $this->persistState();
        // A 404 on the metadata itself makes the fetch throw. Close the EM first to simulate the
        // aftermath of a failed flush: handleProviderFailure must reset the manager, reload the
        // state and still persist the failure counter (M1) — without throwing.
        $client = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 404]));
        $this->em->close();

        $this->makeRunner($client)->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertSame(1, $reloaded->getConsecutiveFailures());
        self::assertNotNull($reloaded->getLastErrorMessage());
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->isOpen(), 'The EM must be usable again after the reset.');
    }

    /**
     * #312: a failure below the threshold is a warning — at error level every transient connect
     * timeout reached MONOLOG_ERROR_TO. Only the failure that trips the breaker is an error.
     */
    public function testProviderFailureIsAWarningUntilTheBreakerTrips(): void
    {
        $state = $this->persistState();
        $client = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 404]));
        $log = new TestHandler();
        $runner = $this->makeRunner($client, failureThreshold: 2, logger: new Logger('test', [$log]));

        $runner->runForProvider($state, []);
        self::assertTrue($log->hasWarning('CSAF provider failed'));
        self::assertFalse($log->hasErrorRecords(), 'One failure below the threshold must not reach the error mail.');

        $runner->runForProvider($this->reload(), []);
        self::assertTrue($log->hasError('CSAF provider failed'), 'The failure that pauses the provider is an error.');
        self::assertSame(CsafProviderStatus::AutoPaused, $this->reload()->getStatus());
    }

    /**
     * Issue #46: one feed of a two-feed provider throws. The provider run still "succeeds"
     * (handleProviderFailure only fires when EVERY feed fails), which is exactly the path that
     * used to advance the watermark past an unread time range and clear the error. The error must
     * stay visible on /import-status.
     */
    public function testFailingFeedSurfacesError(): void
    {
        $state = $this->persistState();

        $this->makeRunner(new MockHttpClient($this->twoFeedResponder()))->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastErrorMessage());
        self::assertStringContainsString('documents stay pending', (string) $reloaded->getLastErrorMessage());
        self::assertStringContainsString(self::FEED_BROKEN_URL, (string) $reloaded->getLastErrorMessage());
        self::assertArrayHasKey(self::FEED_BROKEN_URL, $reloaded->getFailingFeeds());
        // The healthy feed must not be recorded as failing.
        self::assertArrayNotHasKey(self::FEED_URL, $reloaded->getFailingFeeds());
    }

    /**
     * #319, the #46 scenario on the ledger: what a feed listed while it was down is read once it
     * is back — however long the outage lasted, without a grace window.
     */
    public function testDocumentsOfAFailingFeedAreReadOnceItRecovers(): void
    {
        $state = $this->persistState();
        $feedRequests = 0;
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$feedRequests, &$requested): MockResponse {
            $requested[] = $url;
            // Down for the first run only.
            if (str_contains($url, 'rolie-feed') && 1 === ++$feedRequests) {
                return new MockResponse('', ['http_code' => 503]);
            }

            return ($this->responder([
                ['url' => 'https://prov.test/doc-ok.json', 'updated' => '2026-01-01T00:00:00Z'],
            ]))($method, $url);
        });
        $runner = $this->makeRunner($client);

        $runner->runForProvider($state, []);
        self::assertNotContains('https://prov.test/doc-ok.json', $requested);

        $stats = $runner->runForProvider($this->reload(), []);

        self::assertSame(1, $stats['processed'], 'The document the feed listed during the outage is read now.');
    }

    public function testRecoveredFeedClearsTheFailureStreakAndError(): void
    {
        $state = $this->persistState();
        $state->setFailingFeeds([self::FEED_URL => (new \DateTimeImmutable('-1 day'))->format(\DATE_ATOM)]);
        $this->em->flush();

        // Both feeds healthy this time → nothing may remain recorded.
        $this->makeRunner(new MockHttpClient($this->responder([])))->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertSame([], $reloaded->getFailingFeeds());
        self::assertNull($reloaded->getLastErrorMessage());
    }

    /**
     * Provider metadata advertising two feeds, of which one always 404s.
     *
     * @return \Closure(string, string): MockResponse
     */
    private function twoFeedResponder(): \Closure
    {
        return function (string $method, string $url): MockResponse {
            if (str_contains($url, 'metadata.json')) {
                return $this->jsonResponse([
                    'distributions' => [['rolie' => ['feeds' => [
                        ['url' => self::FEED_URL],
                        ['url' => self::FEED_BROKEN_URL],
                    ]]]],
                ]);
            }
            if (str_contains($url, 'rolie-broken')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (str_contains($url, 'rolie-feed')) {
                return $this->jsonResponse(['feed' => ['entry' => []]]);
            }

            return new MockResponse('', ['http_code' => 404]);
        };
    }

    /**
     * @param list<array{url: string, updated: string}> $entries
     *
     * @return \Closure(string, string): MockResponse
     */
    private function responder(array $entries): \Closure
    {
        return function (string $method, string $url) use ($entries): MockResponse {
            if (str_contains($url, 'metadata.json')) {
                return $this->jsonResponse([
                    'distributions' => [['rolie' => ['feeds' => [['url' => self::FEED_URL]]]]],
                ]);
            }
            if (str_contains($url, 'rolie-feed')) {
                return $this->jsonResponse(['feed' => ['entry' => $entries]]);
            }
            if (str_contains($url, 'doc-fail')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (str_contains($url, 'doc-ok')) {
                return new MockResponse($this->csafDocument());
            }

            return new MockResponse('', ['http_code' => 404]);
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(
            (string) json_encode($data),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function csafDocument(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/csaf/bsi_version_range_example.json');
    }

    /**
     * Issue #63: the reminder path is the whole point of baustein 3 — a paused provider must be
     * picked up by a later run even though findRunnable() excludes it. Asserted through the
     * bookkeeping stamp, which is written only after the mail actually went out.
     */
    public function testStillPausedProviderIsRemindedAndStamped(): void
    {
        $paused = new CsafProviderState('paused-prov', 'Paused Provider', 'https://prov.test/paused.json');
        $paused->setStatus(CsafProviderStatus::AutoPaused);
        $paused->setConsecutiveFailures(3);
        $paused->setLastSuccessAt(new \DateTimeImmutable('-74 days'));
        $paused->setLastPauseReminderAt(new \DateTimeImmutable('-30 days'));
        $this->em->persist($paused);
        $this->em->flush();

        $client = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 404]));
        $this->makeRunner($client)->runAll();

        $this->em->clear();
        $reloaded = $this->em->find(CsafProviderState::class, 'paused-prov');
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getLastPauseReminderAt());
        self::assertGreaterThan(
            new \DateTimeImmutable('-1 minute'),
            $reloaded->getLastPauseReminderAt(),
            'A provider paused for 74 days must be reminded about, not left in silence.',
        );
        self::assertSame(CsafProviderStatus::AutoPaused, $reloaded->getStatus(), 'Reminding must not resume the provider.');
    }

    public function testRecentlyRemindedProviderIsLeftAlone(): void
    {
        // Two mails a day per broken provider would be new noise, not a fix.
        $stamp = new \DateTimeImmutable('-2 days');
        $paused = new CsafProviderState('quiet-prov', 'Quiet Provider', 'https://prov.test/quiet.json');
        $paused->setStatus(CsafProviderStatus::AutoPaused);
        $paused->setLastPauseReminderAt($stamp);
        $this->em->persist($paused);
        $this->em->flush();

        $client = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 404]));
        $this->makeRunner($client)->runAll();

        $this->em->clear();
        $reloaded = $this->em->find(CsafProviderState::class, 'quiet-prov');
        self::assertNotNull($reloaded);
        self::assertEquals($stamp->format('Y-m-d H:i'), $reloaded->getLastPauseReminderAt()?->format('Y-m-d H:i'));
    }

    /**
     * #98: Red Hat rebuilds its whole document store daily and writes the rebuild into the
     * document, so `changes.csv` reports thousands of documents a day whose content did not
     * change. Measured 2026-09-11: 60 of 60 sampled documents were pure rebuilds.
     *
     * The original plan — compare ETags — was measured and dropped: Red Hat's ETag is the body's
     * MD5, and the rebuild changes the body (`current_release_date` plus a fresh revision entry),
     * so the ETag changes too. What does survive is the provider's own distinction inside
     * `revision_history` between "Last updated version" and "Last generated version".
     */
    public function testRebuiltDocumentIsFetchedButNotUpserted(): void
    {
        $state = $this->persistState();
        $state->setLastFetchedAt(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        $document = $this->csafDocumentWithRevisionHistory('-2 years', 'now');
        $client = new MockHttpClient($this->directoryResponder(['2026/rebuilt.json'], $document));

        $stats = $this->makeRunner($client)->runForProvider($state, []);

        self::assertSame(0, $stats['processed'], 'A rebuild-only document must not reach the upserter.');
        self::assertSame(1, $stats['rebuildSkipped'], 'It must be counted, so the noise stays visible.');

        // The run is still a success — a rebuild is not an error and must not hold the watermark.
        $reloaded = $this->reload();
        self::assertNotNull($reloaded->getLastSuccessAt());
        self::assertNull($reloaded->getLastErrorMessage());
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $reloaded->getLastFetchedAt());
    }

    /**
     * The counter-test: the same document shape, but with a real change after our watermark. It
     * must be processed — otherwise the filter would quietly swallow genuine advisories.
     */
    public function testGenuinelyChangedDocumentIsStillProcessed(): void
    {
        $state = $this->persistState();
        $state->setLastFetchedAt(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        $document = $this->csafDocumentWithRevisionHistory('-1 day', 'now');
        $client = new MockHttpClient($this->directoryResponder(['2026/changed.json'], $document));

        $stats = $this->makeRunner($client)->runForProvider($state, []);

        self::assertSame(1, $stats['processed'], 'A document whose last substantive change is newer than the watermark must be processed.');
        self::assertSame(0, $stats['rebuildSkipped']);
    }

    /**
     * A provider without the "generated" distinction must be completely unaffected — no document
     * may be filtered away that would previously have been imported.
     */
    public function testProviderWithoutGeneratedMarkerIsUnaffected(): void
    {
        $state = $this->persistState();
        $state->setLastFetchedAt(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        // Old content date, but no revision history telling rebuilds apart.
        $client = new MockHttpClient($this->directoryResponder(['2026/plain.json'], $this->csafDocument()));

        $stats = $this->makeRunner($client)->runForProvider($state, []);

        self::assertSame(1, $stats['processed']);
        self::assertSame(0, $stats['rebuildSkipped']);
    }

    /**
     * #319: without a watermark the rebuild check compares against what the ledger recorded for
     * the document: a republished version whose last substantive change was already read is a
     * rebuild, a later substantive change is processed again.
     */
    public function testRebuildIsRecognisedAgainstTheLedgerRecord(): void
    {
        $state = $this->persistState();
        $feedStamp = '2026-06-01T09:00:00Z';
        $document = $this->csafDocumentWithRevisionHistory('2026-06-01T08:00:00Z', '2026-06-01T09:00:00Z');
        $client = new MockHttpClient(function (string $method, string $url) use (&$feedStamp, &$document): MockResponse {
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse(\sprintf('"2026/rhsa.json","%s"'."\n", $feedStamp));
            }

            return new MockResponse($document);
        });
        $runner = $this->makeRunner($client);

        self::assertSame(1, $runner->runForProvider($state, [])['processed'], 'First sight: processed.');
        self::assertSame('2026-06-01T08:00:00+00:00', $this->record('https://prov.test/advisories/2026/rhsa.json')->getContentChangedAt()?->format(\DATE_ATOM));

        // The provider regenerates the document a day later without changing it.
        $feedStamp = '2026-06-02T09:00:00Z';
        $document = $this->csafDocumentWithRevisionHistory('2026-06-01T08:00:00Z', '2026-06-02T09:00:00Z');
        $stats = $runner->runForProvider($this->reload(), []);
        self::assertSame(0, $stats['processed']);
        self::assertSame(1, $stats['rebuildSkipped']);
        self::assertSame(CsafDocumentOutcome::Rebuild, $this->record('https://prov.test/advisories/2026/rhsa.json')->getOutcome());

        // A real change follows.
        $feedStamp = '2026-06-03T09:00:00Z';
        $document = $this->csafDocumentWithRevisionHistory('2026-06-03T08:00:00Z', '2026-06-03T09:00:00Z');
        self::assertSame(1, $runner->runForProvider($this->reload(), [])['processed'], 'A substantive change after the recorded one is processed.');
    }

    /**
     * #98: the per-provider budget stops one large provider from occupying a whole run. What the
     * budget must never do is lose a document: what it cuts off stays pending.
     *
     * #169: the feed is newest first, as Red Hat's changes.csv is. Taken in feed order, the budget
     * spent itself on the newest document every run and the provider never moved on — so the test
     * drives consecutive runs until the provider is drained.
     */
    public function testBudgetDrainsANewestFirstFeedOldestFirstAcrossRuns(): void
    {
        $state = $this->persistState();
        $fetched = [];
        $client = $this->budgetResponder([
            ['2026/newest.json', '2026-06-03T10:15:30Z'],
            ['2026/middle.json', '2026-06-02T10:15:30Z'],
            ['2026/oldest.json', '2026-06-01T10:15:30Z'],
        ], $fetched);
        $runner = $this->makeRunner($client, maxDocumentsPerProviderRun: 1);

        $stats = $runner->runForProvider($state, []);

        self::assertSame(1, $stats['processed'], 'Exactly one document fits in the budget.');
        self::assertSame(2, $stats['deferred'], 'The rest is deferred and counted.');
        self::assertSame(['https://prov.test/advisories/2026/oldest.json'], $fetched, 'The oldest document goes first; documents past the budget must not even be downloaded.');

        $runner->runForProvider($this->reload(), []);
        $runner->runForProvider($this->reload(), []);
        $stats = $runner->runForProvider($this->reload(), []);

        self::assertSame([
            'https://prov.test/advisories/2026/oldest.json',
            'https://prov.test/advisories/2026/middle.json',
            'https://prov.test/advisories/2026/newest.json',
        ], $fetched, 'Three runs with a budget of one read every document exactly once, oldest first; the fourth has nothing left.');
        self::assertSame(0, $stats['deferred']);
    }

    /**
     * #319: what only the run sees — how much the feed listed recently, and what is still pending —
     * is stored for /import-status.
     */
    public function testRunStoresRecentFeedEntriesAndPendingDocuments(): void
    {
        $state = $this->persistState();
        $fetched = [];
        $recent = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i:s\Z');
        $client = $this->budgetResponder([
            ['2026/recent-a.json', $recent],
            ['2026/recent-b.json', $recent],
            ['2026/old.json', '2026-06-01T10:15:30Z'],
        ], $fetched);

        $this->makeRunner($client, maxDocumentsPerProviderRun: 1)->runForProvider($state, []);

        $reloaded = $this->reload();
        self::assertSame(2, $reloaded->getRecentFeedEntries());
        self::assertSame(2, $reloaded->getPendingDocuments(), 'Two documents deferred by the budget of one.');
    }

    /**
     * #169 on the ledger: the budget may cut through a group of equal timestamps — the remainder
     * has no record and stays pending, so even a group larger than the budget passes.
     */
    public function testBudgetMaySplitAGroupOfEqualTimestamps(): void
    {
        $state = $this->persistState();
        $fetched = [];
        $client = $this->budgetResponder([
            ['2026/later.json', '2026-06-02T00:00:00Z'],
            ['2026/same-a.json', '2026-06-01T00:00:00Z'],
            ['2026/same-b.json', '2026-06-01T00:00:00Z'],
        ], $fetched);
        $runner = $this->makeRunner($client, maxDocumentsPerProviderRun: 1);

        for ($run = 0; $run < 3; ++$run) {
            $runner->runForProvider(0 === $run ? $state : $this->reload(), []);
        }

        self::assertSame([
            'https://prov.test/advisories/2026/same-a.json',
            'https://prov.test/advisories/2026/same-b.json',
            'https://prov.test/advisories/2026/later.json',
        ], $fetched);
    }

    /**
     * #313, the measured case: BSI stamps WID-SEC-W-2026-4133 with the midnight before it went online
     * (10:46Z on 2026-10-23), after that day's first run. Under the watermark every later run
     * dropped it as old. On the ledger the stamp does not matter: an entry without a record is
     * pending, however far back it is stamped.
     */
    public function testLatePublishedDocumentWithABackdatedStampIsRead(): void
    {
        $state = $this->persistState();
        $listed = [['2026/wid-sec-w-2026-3999.json', '2026-10-22T22:00:00.000+00:00']];
        $fetched = [];
        $document = $this->csafDocumentWithRevisionHistory('now', 'now');
        $client = new MockHttpClient(function (string $method, string $url) use (&$fetched, &$listed, $document): MockResponse {
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse(implode('', array_map(static fn (array $e): string => \sprintf('"%s","%s"'."\n", $e[0], $e[1]), $listed)));
            }
            $fetched[] = $url;

            return new MockResponse($document);
        });
        $runner = $this->makeRunner($client);

        // 04:40Z: the first run of the day reads what is online.
        $runner->runForProvider($state, []);
        // 10:46Z: BSI publishes 4133, stamped on the midnight before — older than the first run.
        $listed[] = ['2026/wid-sec-w-2026-4133.json', '2026-10-22T22:00:00.000+00:00'];
        $runner->runForProvider($this->reload(), []);

        self::assertSame([
            'https://prov.test/advisories/2026/wid-sec-w-2026-3999.json',
            'https://prov.test/advisories/2026/wid-sec-w-2026-4133.json',
        ], $fetched, 'The late document is read by the next run, the handled one not again.');
    }

    /**
     * #319 bootstrap: on the first ledger run, entries older than the old watermark minus a day
     * were read under the old regime — they are recorded without a download. Everything newer is
     * pending (the day covers BSI's date-only stamps, #313).
     */
    public function testBootstrapRecordsEntriesBeforeTheOldWatermarkWithoutDownloading(): void
    {
        $state = $this->persistState();
        $state->setLastFetchedAt(new \DateTimeImmutable('2026-10-23T04:40:00Z'));
        $this->em->flush();

        $fetched = [];
        $client = $this->budgetResponder([
            ['2026/after.json', '2026-10-23T09:15:42Z'],
            ['2026/on-the-midnight.json', '2026-10-22T22:00:00Z'],
            ['2026/long-read.json', '2026-10-20T22:00:00Z'],
        ], $fetched);

        $this->makeRunner($client)->runForProvider($state, []);

        self::assertSame([
            'https://prov.test/advisories/2026/on-the-midnight.json',
            'https://prov.test/advisories/2026/after.json',
        ], $fetched);
        $bootstrapped = $this->record('https://prov.test/advisories/2026/long-read.json');
        self::assertSame(CsafDocumentOutcome::Processed, $bootstrapped->getOutcome());
        self::assertNull($bootstrapped->getDocId(), 'Recorded without a download.');
        self::assertSame('2026-10-23T04:40:00+00:00', $bootstrapped->getHandledAt()->format(\DATE_ATOM), 'Handled by the old watermark — not counted as read this week on /import-status.');
        self::assertSame('2026-10-22T04:40:00+00:00', $this->reload()->getLedgerSince()?->format(\DATE_ATOM));
    }

    /**
     * The bootstrap runs once. Afterwards an entry older than the old watermark is pending like any
     * other — exactly the case (a backdated stamp) the ledger exists for.
     */
    public function testBootstrapRunsOnlyOnce(): void
    {
        $state = $this->persistState();
        $state->setLastFetchedAt(new \DateTimeImmutable('2026-10-23T04:40:00Z'));
        $this->em->flush();
        $fetched = [];
        $runner = $this->makeRunner($this->budgetResponder([['2026/a.json', '2026-10-20T22:00:00Z']], $fetched));
        $runner->runForProvider($state, []);
        self::assertSame([], $fetched);

        $fetched = [];
        $client = $this->budgetResponder([
            ['2026/a.json', '2026-10-20T22:00:00Z'],
            ['2026/backdated.json', '2026-10-19T22:00:00Z'],
        ], $fetched);
        $this->makeRunner($client)->runForProvider($this->reload(), []);

        self::assertSame(['https://prov.test/advisories/2026/backdated.json'], $fetched);
    }

    /**
     * A provider that never ran has no watermark to trust: everything within the horizon is read,
     * as the initial window used to be.
     */
    public function testNewProviderReadsTheWholeHorizon(): void
    {
        $state = $this->persistState();
        $fetched = [];
        $client = $this->budgetResponder([
            ['2026/b.json', '2026-06-02T00:00:00Z'],
            ['2026/a.json', '2026-06-01T00:00:00Z'],
        ], $fetched);

        $this->makeRunner($client)->runForProvider($state, []);

        self::assertCount(2, $fetched);
        self::assertNotNull($this->reload()->getLedgerSince());
    }

    /**
     * A directory-based provider serving the given changes.csv rows (in the given order), every
     * document answering with a fresh, genuinely changed body. Downloaded document URLs are
     * recorded in $fetched.
     *
     * @param list<array{0: string, 1: string}> $entries path and updated timestamp
     * @param list<string>                      $fetched
     */
    private function budgetResponder(array $entries, array &$fetched): MockHttpClient
    {
        $csv = implode('', array_map(static fn (array $e): string => \sprintf('"%s","%s"'."\n", $e[0], $e[1]), $entries));
        $document = $this->csafDocumentWithRevisionHistory('now', 'now');

        return new MockHttpClient(function (string $method, string $url) use ($csv, $document, &$fetched) {
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse($csv);
            }
            $fetched[] = $url;

            return new MockResponse($document);
        });
    }

    /**
     * Feed a directory-based provider a fixed list of document paths, all answering with the same
     * body.
     *
     * @param list<string> $paths
     */
    private function directoryResponder(array $paths, string $document): \Closure
    {
        $csv = implode('', array_map(
            static fn (string $path): string => \sprintf('"%s","%s"'."\n", $path, (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s\Z')),
            $paths,
        ));

        return function (string $method, string $url) use ($csv, $document) {
            if (self::METADATA_URL === $url) {
                return $this->jsonResponse(['distributions' => [['directory_url' => 'https://prov.test/advisories/']]]);
            }
            if (str_ends_with($url, '/changes.csv')) {
                return new MockResponse($csv);
            }

            return new MockResponse($document);
        };
    }

    /**
     * The shared fixture, extended with the revision history that lets a rebuild be told apart
     * from a real change — the shape Red Hat actually serves.
     */
    private function csafDocumentWithRevisionHistory(string $lastUpdated, string $lastGenerated): string
    {
        /** @var array<string, mixed> $doc */
        $doc = json_decode($this->csafDocument(), true, 512, \JSON_THROW_ON_ERROR);
        $updated = new \DateTimeImmutable($lastUpdated);
        $generated = new \DateTimeImmutable($lastGenerated);

        $doc['document']['tracking']['current_release_date'] = $generated->format(\DATE_ATOM);
        $doc['document']['tracking']['revision_history'] = [
            ['number' => '1', 'date' => $updated->format(\DATE_ATOM), 'summary' => 'Last updated version'],
            ['number' => '2', 'date' => $generated->format(\DATE_ATOM), 'summary' => 'Last generated version'],
        ];

        return json_encode($doc, \JSON_THROW_ON_ERROR);
    }

    private function persistState(string $metadataUrl = self::METADATA_URL): CsafProviderState
    {
        $state = new CsafProviderState('prov_test', 'Provider Test', $metadataUrl);
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    private function records(): CsafDocumentRecordRepository
    {
        // Through the current manager, like reload(): a test may have triggered a resetManager().
        $repository = $this->registry->getManager()->getRepository(CsafDocumentRecord::class);
        self::assertInstanceOf(CsafDocumentRecordRepository::class, $repository);

        return $repository;
    }

    private function record(string $url): CsafDocumentRecord
    {
        $this->registry->getManager()->clear();
        $record = $this->records()->findOneByDocument('prov_test', $url);
        self::assertNotNull($record, \sprintf('No ledger record for %s.', $url));

        return $record;
    }

    private function reload(): CsafProviderState
    {
        // Always read through the current manager: a test may have triggered a resetManager().
        $state = $this->registry->getManager()->getRepository(CsafProviderState::class)->find('prov_test');
        self::assertInstanceOf(CsafProviderState::class, $state);

        return $state;
    }

    /**
     * @param int $maxDocumentsPerProviderRun #98 per-provider budget; 0 disables the cap, which is
     *                                        what every test that is not about the cap itself wants
     */
    private function makeRunner(HttpClientInterface $client, int $failureThreshold = 99, int $maxDocumentAttempts = 5, int $maxDocumentsPerProviderRun = 0, ?LoggerInterface $logger = null): CsafImportRunner
    {
        $container = self::getContainer();
        /** @var CsafProviderStateRepository $stateRepo */
        $stateRepo = $container->get(CsafProviderStateRepository::class);
        /** @var SoftwareRepository $softwareRepo */
        $softwareRepo = $container->get(SoftwareRepository::class);
        /** @var CsafDocumentParser $parser */
        $parser = $container->get(CsafDocumentParser::class);
        /** @var VulnerabilityUpserter $upserter */
        $upserter = $container->get(VulnerabilityUpserter::class);
        /** @var MailerInterface $mailer */
        $mailer = $container->get(MailerInterface::class);

        $logger ??= new NullLogger();
        $metadataFetcher = new ProviderMetadataFetcher($client, 30);
        $rolieReader = new RolieFeedReader($client, 30);
        $changesCsvReader = new ChangesCsvReader($client, 30);
        $aggregator = new AggregatorExpander($client, $stateRepo, $this->em, $logger, 30);
        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = $container->get(\App\Repository\UserRepository::class);
        $recipients = new \App\Service\Notification\OperatorRecipients($userRepository);
        $failureNotifier = new \App\Service\Csaf\AdminFailureNotifier($mailer, $logger, $recipients, null, $failureThreshold);

        return new CsafImportRunner(
            $stateRepo,
            $this->records(),
            $softwareRepo,
            $metadataFetcher,
            $rolieReader,
            $changesCsvReader,
            $parser,
            $upserter,
            $aggregator,
            $failureNotifier,
            $client,
            $this->registry,
            $logger,
            self::METADATA_URL,
            'https://prov.test/aggregator.json',
            // Retention: long enough for the fixed 2026 stamps below to stay within the horizon.
            3650,
            $maxDocumentAttempts,
            $failureThreshold,
            7,
            30,
            50,
            $maxDocumentsPerProviderRun,
            '',
        );
    }
}
