<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        // The rate-limiter cache pool is filesystem-backed and persists across test runs; fixed
        // test identifiers (testadmin, sec001, …) would otherwise accumulate into spurious 429s on
        // repeated runs within the hour. Wipe it before every test so each starts with a clean
        // budget. Deliberate rate-limit tests clear-then-consume within their own method.
        /** @var \Psr\Cache\CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = $this->client->getContainer()->get('cache.rate_limiter');
        $rateLimiterCache->clear();

        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
    }

    protected function loginAsAdmin(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('testadmin');
        $user->setFirstName('Test');
        $user->setLastName('Admin');
        $user->setEmail('testadmin@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_ADMIN, User::ROLE_HARDWARE_OWNER, User::ROLE_ISB, User::ROLE_SOFTWARE_OWNER]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        // Rate-limiter storage persists across test runs and is keyed by this fixed identifier —
        // reset the per-user action limiters so each test starts with a full budget instead of
        // accumulating into a 429. Mirrors PasswordChangeRateLimitTest::resetLimiter.
        $this->resetActionLimiters($user->getUserIdentifier());

        return $user;
    }

    protected function resetActionLimiters(string $userIdentifier): void
    {
        foreach (['limiter.delete_action_limiter', 'limiter.password_change_limiter'] as $service) {
            /** @var \Symfony\Component\RateLimiter\RateLimiterFactoryInterface $factory */
            $factory = $this->client->getContainer()->get($service);
            $factory->create($userIdentifier)->reset();
        }
    }

    protected function loginAsSoftwareOwner(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('testsoftwareowner');
        $user->setFirstName('Test');
        $user->setLastName('SoftwareOwner');
        $user->setEmail('testsoftwareowner@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_SOFTWARE_OWNER]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    protected function loginAsHardwareOwner(): User
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('testowner');
        $user->setFirstName('Test');
        $user->setLastName('Owner');
        $user->setEmail('testowner@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_HARDWARE_OWNER]);

        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    protected function createAssetGroup(): AssetGroup
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetType = new AssetType();
        $assetType->setName('Web Application');
        $assetType->setActive(true);
        $em->persist($assetType);

        $assetGroup = new AssetGroup();
        $assetGroup->setName('Test Asset Group');
        $assetGroup->setAssetType($assetType);
        $assetGroup->setActive(true);
        $em->persist($assetGroup);

        $em->flush();

        return $assetGroup;
    }
}
