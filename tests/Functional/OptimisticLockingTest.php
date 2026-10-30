<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OptimisticLockingTest extends AbstractWebTestCase
{
    public function testVulnerabilityEditConflictRendersBannerAndRecovers(): void
    {
        $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroup();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vuln = $this->seedVulnerability($em, $assetGroup);

        $crawler = $this->client->request('GET', '/vulnerability/'.$vuln->getUuid().'/edit');
        $this->assertResponseIsSuccessful();

        // Simulate concurrent edit by another user: bump lockVersion AND change a visible field
        // via raw SQL (Doctrine never sees this in the current EM, so the form-bound version stays stale).
        $em->getConnection()->executeStatement(
            'UPDATE vulnerability SET lock_version = lock_version + 1, title = ? WHERE id = ?',
            ['Title From Other User', $vuln->getId()],
        );

        $form = $crawler->selectButton('Update')->form([
            'vulnerability[title]' => 'Title After Conflict',
        ]);
        $this->client->submit($form);

        // Conflict path: form is re-rendered with status 422 (Turbo's "render new HTML"
        // signal — a 200 would be silently discarded by Turbo Drive, leaving the submit
        // button stuck in its loading state on the client). UX decision: discard the user's
        // submitted input and load the OTHER user's persisted state, so the conflicted user
        // sees what changed before re-deciding.
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-warning .alert-title', 'Conflict detected');
        $this->assertInputValueSame('vulnerability[title]', 'Title From Other User');

        // The hidden lockVersion field carries the latest DB value, so the next submit succeeds.
        $crawler = $this->client->getCrawler();
        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(Vulnerability::class)->findOneBy(['uuid' => $vuln->getUuid()]);
        $this->assertNotNull($reloaded);
        // Re-submit went through unchanged → DB still holds the OTHER user's title.
        $this->assertSame('Title From Other User', $reloaded->getTitle());
    }

    public function testUserEditConflictRendersBannerInsteadOfFailingOnReservedTableName(): void
    {
        $this->loginAsAdmin();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setUsername('conflicted');
        $user->setFirstName('Con');
        $user->setLastName('Flicted');
        $user->setEmail('conflicted@example.com');
        $user->setPassword('unused');
        $em->persist($user);
        $em->flush();

        $crawler = $this->client->request('GET', '/user/'.$user->getUuid().'/edit');
        $this->assertResponseIsSuccessful();

        // `user` is a reserved word in PostgreSQL: the conflict resolver's raw lookup used the
        // bare table name, hit current_user instead of the table and died with a 500.
        $em->getConnection()->executeStatement(
            'UPDATE "user" SET lock_version = lock_version + 1, first_name = ? WHERE id = ?',
            ['OtherAdmin', $user->getId()],
        );

        $form = $crawler->filter('form[name="user"]')->form(['user[firstName]' => 'Mine']);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-warning .alert-title', 'Conflict detected');
        $this->assertInputValueSame('user[firstName]', 'OtherAdmin');
    }

    public function testOccurrenceStatusUpdateConflictRedirectsWithWarning(): void
    {
        $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroup();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $vuln = $this->seedVulnerability($em, $assetGroup);
        // Trigger occurrence creation by adding the asset group again (already present from seed)
        // — instead, fetch the occurrence Doctrine auto-created at flush time.
        $occurrence = $em->getRepository(VulnerabilityOccurrence::class)
            ->findOneBy(['vulnerability' => $vuln, 'assetGroup' => $assetGroup]);
        $this->assertNotNull($occurrence, 'Asset group attachment should auto-create a VulnerabilityOccurrence.');

        // Render the modal body to obtain a live form with the current lockVersion baked in
        $crawler = $this->client->request(
            'GET',
            '/vulnerability/'.$vuln->getUuid().'/occurrence/'.$occurrence->getUuid().'/edit?redirect=show',
        );
        $this->assertResponseIsSuccessful();

        // Simulate concurrent edit
        $em->getConnection()->executeStatement(
            'UPDATE vulnerability_occurrence SET lock_version = lock_version + 1 WHERE id = ?',
            [$occurrence->getId()],
        );

        $form = $crawler->selectButton('Update Status')->form([
            'vulnerability_occurrence_status[status]' => VulnerabilityStatus::InProgress->value,
        ]);
        $this->client->submit($form);

        // Tests bypass Turbo headers, so the controller takes the redirect branch with a warning flash
        $this->assertResponseRedirects();
        $session = $this->client->getRequest()->getSession();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Session\Session::class, $session);
        $this->assertSame(['common.optimistic_lock.reload_required'], $session->getFlashBag()->peek('warning'));
    }

    private function seedVulnerability(EntityManagerInterface $em, \App\Entity\AssetGroup $assetGroup): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setTitle('Original Title');
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->addAssetGroup($assetGroup);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));

        $em->persist($vuln);
        $em->flush();

        return $vuln;
    }
}
