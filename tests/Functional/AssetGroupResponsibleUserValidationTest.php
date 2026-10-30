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
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AssetGroupResponsibleUserValidationTest extends AbstractWebTestCase
{
    public function testNullResponsibleUserIsValid(): void
    {
        $fx = $this->setupFixtures();
        $ag = $this->assetGroup('Unassigned', $fx['assetType']);

        self::assertCount(0, $this->validator()->validate($ag));
    }

    public function testActiveUserInAlignedUserGroupIsValid(): void
    {
        $fx = $this->setupFixtures();
        $ag = $this->assetGroup('Aligned', $fx['assetType']);
        $ag->setResponsibleUser($fx['alignedUser']);

        self::assertCount(0, $this->validator()->validate($ag));
    }

    public function testInactiveUserIsRejected(): void
    {
        $fx = $this->setupFixtures();
        $ag = $this->assetGroup('Inactive-owner', $fx['assetType']);
        $ag->setResponsibleUser($fx['inactiveUser']);

        $this->assertSingleViolation(
            $this->validator()->validate($ag),
            'responsibleUser',
            'asset_group.responsible_user.inactive',
        );
    }

    public function testActiveUserWithoutAlignedUserGroupIsRejected(): void
    {
        $fx = $this->setupFixtures();
        $ag = $this->assetGroup('Misaligned', $fx['assetType']);
        $ag->setResponsibleUser($fx['unalignedUser']);

        $this->assertSingleViolation(
            $this->validator()->validate($ag),
            'responsibleUser',
            'asset_group.responsible_user.not_aligned',
        );
    }

    public function testUserInUserGroupLinkedToDifferentAssetTypeIsRejected(): void
    {
        $fx = $this->setupFixtures();
        $ag = $this->assetGroup('Other-type', $fx['otherAssetType']);
        $ag->setResponsibleUser($fx['alignedUser']);

        $this->assertSingleViolation(
            $this->validator()->validate($ag),
            'responsibleUser',
            'asset_group.responsible_user.not_aligned',
        );
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     */
    private function assertSingleViolation(iterable $violations, string $expectedPath, string $expectedTemplate): void
    {
        $count = 0;
        foreach ($violations as $violation) {
            self::assertInstanceOf(ConstraintViolationInterface::class, $violation);
            self::assertSame($expectedPath, $violation->getPropertyPath());
            self::assertSame($expectedTemplate, $violation->getMessageTemplate());
            ++$count;
        }

        self::assertSame(1, $count);
    }

    /**
     * @return array{
     *     assetType: AssetType,
     *     otherAssetType: AssetType,
     *     alignedUser: User,
     *     unalignedUser: User,
     *     inactiveUser: User
     * }
     */
    private function setupFixtures(): array
    {
        $em = $this->em();

        $assetType = new AssetType();
        $assetType->setName('Web Server');
        $assetType->setActive(true);
        $em->persist($assetType);

        $otherAssetType = new AssetType();
        $otherAssetType->setName('Database');
        $otherAssetType->setActive(true);
        $em->persist($otherAssetType);

        $alignedUser = $this->user('aligned');
        $unalignedUser = $this->user('unaligned');
        $inactiveUser = $this->user('inactive');
        $inactiveUser->setIsActive(false);

        $userGroup = new UserGroup();
        $userGroup->setName('Aligned Team');
        $alignedUser->addUserGroup($userGroup);
        $assetType->addUserGroup($userGroup);
        $em->persist($userGroup);

        $em->persist($alignedUser);
        $em->persist($unalignedUser);
        $em->persist($inactiveUser);

        $em->flush();

        return [
            'assetType' => $assetType,
            'otherAssetType' => $otherAssetType,
            'alignedUser' => $alignedUser,
            'unalignedUser' => $unalignedUser,
            'inactiveUser' => $inactiveUser,
        ];
    }

    private function assetGroup(string $name, AssetType $type): AssetGroup
    {
        $ag = new AssetGroup();
        $ag->setName($name);
        $ag->setAssetType($type);
        $ag->setActive(true);

        return $ag;
    }

    private function user(string $username): User
    {
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName(ucfirst($username));
        $user->setLastName('Persona');
        $user->setEmail($username.'@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_USER]);

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function validator(): ValidatorInterface
    {
        /** @var ValidatorInterface $validator */
        $validator = $this->client->getContainer()->get('validator');

        return $validator;
    }
}
