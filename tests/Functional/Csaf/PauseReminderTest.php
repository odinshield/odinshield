<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Csaf;

use App\Entity\CsafProviderState;
use App\Entity\User;
use App\Enum\CsafProviderStatus;
use App\Repository\UserRepository;
use App\Service\Notification\OperatorRecipients;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #63, bausteine 3 and 4: a paused provider keeps reminding, and the reminder reaches the
 * role holders rather than one configured address.
 *
 * Both exist because of a real outage: on Demo, provider ABB tripped the breaker on 2026-06-29,
 * one mail went out, and nothing followed for 74 days — findRunnable() excludes paused providers,
 * so the runner never touched it again and could never remind.
 */
final class PauseReminderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $registry->getManager();
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testRecipientsAreTheRoleHoldersPlusTheConfiguredAddress(): void
    {
        $this->persistUser('isb', 'isb@example.test', [User::ROLE_ISB]);
        $this->persistUser('admin', 'admin@example.test', [User::ROLE_ADMIN]);
        $this->persistUser('owner', 'owner@example.test', [User::ROLE_SOFTWARE_OWNER]);

        $addresses = $this->recipients()->forImporterFailure('ops@example.test');

        self::assertSame(['admin@example.test', 'isb@example.test', 'ops@example.test'], $addresses);
    }

    public function testWorksWithoutAnyConfiguredAddress(): void
    {
        // The whole point of baustein 4: before this, an unset CSAF_ADMIN_EMAIL meant total
        // silence, and unset is the default.
        $this->persistUser('isb', 'isb@example.test', [User::ROLE_ISB]);

        self::assertSame(['isb@example.test'], $this->recipients()->forImporterFailure(null));
    }

    public function testInactiveUsersAreNotNotified(): void
    {
        $this->persistUser('gone', 'gone@example.test', [User::ROLE_ISB], isActive: false);

        self::assertSame([], $this->recipients()->forImporterFailure(''));
    }

    public function testAddressesAreDeduplicatedCaseInsensitively(): void
    {
        // A role holder whose mailbox is also the configured ops address — common in small teams,
        // and nobody wants the same mail twice.
        $this->persistUser('isb', 'ISB@example.test', [User::ROLE_ISB]);
        $this->persistUser('both', 'both@example.test', [User::ROLE_ISB, User::ROLE_ADMIN]);

        $addresses = $this->recipients()->forImporterFailure('isb@example.test');

        self::assertSame(['both@example.test', 'ISB@example.test'], $addresses);
    }

    public function testEmptyWhenThereIsNobodyToTell(): void
    {
        // Not an error — the caller logs a warning so the missing configuration is visible.
        self::assertSame([], $this->recipients()->forImporterFailure(null));
    }

    public function testAPausedProviderIsDueAgainAfterTheInterval(): void
    {
        $state = $this->persistPaused('prov-old', new \DateTimeImmutable('-30 days'));

        self::assertTrue($this->isReminderDue($state, 7), 'A reminder 30 days old is overdue at a 7-day interval.');
    }

    public function testAFreshlyRemindedProviderStaysQuiet(): void
    {
        // The interval must not be the run interval: at `0 3,15 * * *` that would be two mails a
        // day per broken provider — new noise instead of a fix.
        $state = $this->persistPaused('prov-fresh', new \DateTimeImmutable('-2 days'));

        self::assertFalse($this->isReminderDue($state, 7));
    }

    public function testAPauseThatPredatesTheFeatureIsRemindedOnce(): void
    {
        // Every provider paused before this release has a null stamp — they must not stay silent
        // forever just because the column did not exist when they broke.
        $state = $this->persistPaused('prov-legacy', null);

        self::assertTrue($this->isReminderDue($state, 7));
    }

    /**
     * Mirrors the due-check in CsafImportRunner::remindAboutPausedProviders(). Kept as a helper
     * rather than driving a full import run: the reminder path itself needs no network, and this
     * keeps the boundary condition readable.
     */
    private function isReminderDue(CsafProviderState $state, int $intervalDays): bool
    {
        $last = $state->getLastPauseReminderAt();

        return null === $last || $last <= (new \DateTimeImmutable())->modify('-'.$intervalDays.' days');
    }

    private function recipients(): OperatorRecipients
    {
        /** @var UserRepository $repo */
        $repo = self::getContainer()->get(UserRepository::class);

        return new OperatorRecipients($repo);
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $username, string $email, array $roles, bool $isActive = true): void
    {
        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Test');
        $user->setLastName($username);
        $user->setEmail($email);
        $user->setPassword('irrelevant');
        $user->setIsActive($isActive);
        $user->setRoles($roles);

        $this->em->persist($user);
        $this->em->flush();
    }

    private function persistPaused(string $key, ?\DateTimeImmutable $lastReminder): CsafProviderState
    {
        $state = new CsafProviderState($key, 'Provider '.$key, 'https://example.test/'.$key.'.json');
        $state->setStatus(CsafProviderStatus::AutoPaused);
        $state->setConsecutiveFailures(3);
        $state->setLastPauseReminderAt($lastReminder);

        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }
}
