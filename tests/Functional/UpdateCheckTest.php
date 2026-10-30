<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\UpdateCheckState;
use App\Entity\User;
use App\Repository\UpdateCheckStateRepository;
use App\Service\UpdateCheck\UpdateChecker;
use App\Service\UpdateCheck\UpdateNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;

/**
 * #223: daily check of the published version.json, dashboard hint for ISB and admin, one mail
 * per release to the administrators.
 */
final class UpdateCheckTest extends AbstractWebTestCase
{
    private const string URL = 'https://example.org/version.json';

    public function testNewerReleaseIsStoredAndMailedToAdminsOnce(): void
    {
        $this->createUser('admin223', [User::ROLE_ADMIN]);
        $this->createUser('isb223', [User::ROLE_ISB]);

        $requests = [];
        $checker = $this->checker(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode([
                'version' => '99.0.0',
                'released' => '2026-11-02',
                'security' => true,
                'url' => 'https://example.org/releases/tag/v99.0.0',
            ]));
        });

        self::assertTrue($checker->run());
        self::assertTrue($checker->run(), 'second run, same release');

        // A bare GET of the configured URL — nothing about the instance travels with it.
        self::assertSame([['method' => 'GET', 'url' => self::URL, 'body' => ''], ['method' => 'GET', 'url' => self::URL, 'body' => '']], $requests);

        $update = $checker->availableUpdate();
        self::assertNotNull($update);
        self::assertSame('99.0.0', $update->version);
        self::assertTrue($update->security);
        self::assertSame('https://example.org/releases/tag/v99.0.0', $update->url);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['admin223@example.com'], array_map(static fn ($a): string => $a->getAddress(), $email->getTo()), 'admins only, not the ISB');
        $this->assertEmailSubjectContains($email, 'Sicherheitsupdate verfügbar: Version 99.0.0');
    }

    public function testCurrentVersionIsNoUpdate(): void
    {
        $this->createUser('admin223', [User::ROLE_ADMIN]);
        $current = $this->appVersion();

        $checker = $this->checker(static fn (): MockResponse => new MockResponse((string) json_encode(['version' => $current])));

        self::assertTrue($checker->run());
        self::assertNull($checker->availableUpdate());
        $this->assertEmailCount(0);
    }

    public function testUnreachableOrGarbledSourceKeepsTheLastGoodAnswer(): void
    {
        $this->storeState('99.0.0');

        foreach ([new MockResponse('', ['http_code' => 404]), new MockResponse('{"version": "latest"}'), new MockResponse('not json')] as $response) {
            $checker = $this->checker(static fn (): MockResponse => $response);
            self::assertFalse($checker->run());
        }

        $state = $this->state();
        self::assertSame('99.0.0', $state->getLatestVersion());
        self::assertNotNull($state->getLastErrorMessage());
    }

    public function testNonHttpsLinkIsDropped(): void
    {
        $checker = $this->checker(static fn (): MockResponse => new MockResponse((string) json_encode([
            'version' => '99.0.0',
            'url' => 'javascript:alert(1)',
        ])));

        self::assertTrue($checker->run());
        self::assertNull($checker->availableUpdate()?->url);
        self::assertSame('99.0.0', $checker->availableUpdate()?->version);
    }

    public function testDisabledCheckNeitherFetchesNorShows(): void
    {
        $this->storeState('99.0.0');
        $checker = $this->checker(static fn (): never => throw new \LogicException('must not fetch'), enabled: false);

        self::assertTrue($checker->run());
        self::assertNull($checker->availableUpdate());
    }

    public function testDashboardShowsTheHintToIsbAndAdminOnly(): void
    {
        $this->storeState('99.0.0', security: true);

        foreach (['isb' => [User::ROLE_ISB], 'admin' => [User::ROLE_ADMIN], 'softowner' => [User::ROLE_SOFTWARE_OWNER], 'plain' => []] as $name => $roles) {
            $this->client->loginUser($this->createUser($name.'223', $roles));
            $crawler = $this->client->request('GET', '/');
            self::assertResponseIsSuccessful($name);

            $banner = $crawler->filter('.alert:contains("99.0.0")');
            if ([] === $roles || [User::ROLE_SOFTWARE_OWNER] === $roles) {
                self::assertCount(0, $banner, $name.' sees no update hint');
                continue;
            }
            self::assertCount(1, $banner, $name);
            self::assertStringContainsString('alert-warning', (string) $banner->attr('class'), 'security release is a warning');
            self::assertSame('_blank', $banner->filter('a')->attr('target'));
        }
    }

    public function testDashboardLoadsNormallyWithoutAnyCheckResult(): void
    {
        $this->client->loginUser($this->createUser('isb223', [User::ROLE_ISB]));
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.alert:contains("OdinShield 99")'));
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $responder
     */
    private function checker(callable $responder, bool $enabled = true): UpdateChecker
    {
        $container = $this->client->getContainer();
        /** @var UpdateCheckStateRepository $repository */
        $repository = $container->get(UpdateCheckStateRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        /** @var UpdateNotifier $notifier */
        $notifier = $container->get(UpdateNotifier::class);

        return new UpdateChecker(
            new MockHttpClient($responder),
            $repository,
            $em,
            $notifier,
            new NullLogger(),
            $enabled,
            self::URL,
            5,
            $this->appVersion(),
        );
    }

    private function appVersion(): string
    {
        $version = $this->client->getContainer()->getParameter('app_version');
        self::assertIsString($version);

        return $version;
    }

    private function storeState(string $latestVersion, bool $security = false): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $state = (new UpdateCheckState())
            ->setLatestVersion($latestVersion)
            ->setLatestSecurity($security)
            ->setLatestUrl('https://example.org/releases/tag/v'.$latestVersion)
            ->setLastSuccessAt(new \DateTimeImmutable());
        $em->persist($state);
        $em->flush();
    }

    private function state(): UpdateCheckState
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $state = $em->find(UpdateCheckState::class, UpdateCheckState::ID);
        self::assertNotNull($state);

        return $state;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $username, array $roles): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Up');
        $user->setLastName('Date');
        $user->setEmail($username.'@example.com');
        $user->setPassword('unused');
        $user->setIsActive(true);
        $user->setLocale('de');
        $user->setMustChangePassword(false);
        $user->setRoles($roles);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
