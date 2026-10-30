<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * The checker rejects a deactivated account when it authenticates (form login, remember-me). It
 * does not run for an existing session; that case (audit M8, #184) is covered end to end by
 * Functional\Security\DeactivatedUserSessionTest.
 */
final class UserCheckerTest extends TestCase
{
    public function testCheckPostAuthRejectsDeactivatedUser(): void
    {
        $user = new User();
        $user->setIsActive(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        (new UserChecker())->checkPostAuth($user);
    }

    public function testCheckPostAuthAllowsActiveUser(): void
    {
        $user = new User();
        $user->setIsActive(true);

        (new UserChecker())->checkPostAuth($user);
        $this->expectNotToPerformAssertions();
    }
}
