<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Command;

use App\Command\OdinshieldSeedUsersCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * I-06 (#200): outside dev/test the seed command refuses before touching the database.
 */
final class OdinshieldSeedUsersCommandTest extends TestCase
{
    public function testRefusesInProdWithoutWriting(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $command = new OdinshieldSeedUsersCommand($em, $this->createStub(UserPasswordHasherInterface::class), 'prod');

        $output = new BufferedOutput();
        $status = $command(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('only runs in dev/test (current: prod)', $output->fetch());
    }
}
