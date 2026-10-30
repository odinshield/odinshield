<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Utils\Validator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Stopwatch\Stopwatch;

use function Symfony\Component\String\u;

#[AsCommand(
    name: 'odinshield:user:add',
    description: 'Creates a new user account.',
    help: 'This command allows you to create a user account.',
    usages: [
        'odinshield:user:add john John Doe john.doe@example.com s3cr3t!PW',
        'odinshield:user:add john John Doe john.doe@example.com s3cr3t!PW --admin',
    ],
)]
class OdinshieldAddUserCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Validator $validator,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username of the user.')
            ->addArgument('first-name', InputArgument::REQUIRED, 'The first name of the user.')
            ->addArgument('last-name', InputArgument::REQUIRED, 'The last name of the user.')
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user.')
            ->addArgument('password', InputArgument::REQUIRED, 'The plain text password of the user.')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant the user admin role (ROLE_ADMIN).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The locale of the user (e.g. de, en).', 'de')
            ->addOption('must-change-password', null, InputOption::VALUE_NONE, 'Require the user to change their password on next login.')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        /** @var string|null $username */
        $username = $input->getArgument('username');
        /** @var string|null $firstName */
        $firstName = $input->getArgument('first-name');
        /** @var string|null $lastName */
        $lastName = $input->getArgument('last-name');
        /** @var string|null $email */
        $email = $input->getArgument('email');
        /** @var string|null $password */
        $password = $input->getArgument('password');

        if (null !== $username && null !== $firstName && null !== $lastName && null !== $email && null !== $password) {
            return;
        }

        $this->io->title('Add User Command Interactive Wizard');
        $this->io->text([
            'If you prefer to not use this interactive wizard, provide the',
            'arguments required by this command as follows:',
            '',
            ' $ php bin/console odinshield:user:add username first-name last-name email@example.com password',
            '',
            'Now we\'ll ask you for the value of all the missing command arguments.',
        ]);

        if (null !== $username) {
            $this->io->text(' > <info>Username</info>: '.$username);
        } else {
            $username = $this->io->ask('Username', null, $this->validator->validateUsername(...));
            $input->setArgument('username', $username);
        }

        if (null !== $firstName) {
            $this->io->text(' > <info>First Name</info>: '.$firstName);
        } else {
            $firstName = $this->io->ask('First Name', null, $this->validator->validateName(...));
            $input->setArgument('first-name', $firstName);
        }

        if (null !== $lastName) {
            $this->io->text(' > <info>Last Name</info>: '.$lastName);
        } else {
            $lastName = $this->io->ask('Last Name', null, $this->validator->validateName(...));
            $input->setArgument('last-name', $lastName);
        }

        if (null !== $email) {
            $this->io->text(' > <info>Email</info>: '.$email);
        } else {
            $email = $this->io->ask('Email', null, $this->validator->validateEmail(...));
            $input->setArgument('email', $email);
        }

        if (null !== $password) {
            $this->io->text(' > <info>Password</info>: '.u('*')->repeat(u($password)->width()));
        } else {
            // #239: askHidden() trims the answer, and spaces now count as special characters — a
            // passphrase with a leading or trailing space would be stored altered and never match
            // at login (the login form does not trim the password).
            $question = new Question('Password (your input will be hidden)');
            $question->setHidden(true)->setTrimmable(false)->setValidator($this->validator->validatePassword(...));
            $password = $this->io->askQuestion($question);
            $input->setArgument('password', $password);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('add-user-command');

        /** @var string $username */
        $username = $input->getArgument('username');
        /** @var string $firstName */
        $firstName = $input->getArgument('first-name');
        /** @var string $lastName */
        $lastName = $input->getArgument('last-name');
        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $plainPassword */
        $plainPassword = $input->getArgument('password');
        /** @var string|null $locale */
        $locale = $input->getOption('locale');
        $isAdmin = (bool) $input->getOption('admin');
        $mustChangePassword = (bool) $input->getOption('must-change-password');

        $this->validateUserData($username, $firstName, $lastName, $plainPassword, $email);

        $user = new User();
        $user->setUsername($username);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setIsActive(true);
        $user->setLocale($locale);
        $user->setMustChangePassword($mustChangePassword);

        if ($isAdmin) {
            $user->setRoles([User::ROLE_ADMIN]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->io->success(\sprintf(
            '%s was successfully created: %s (%s)',
            $isAdmin ? 'Administrator user' : 'User',
            // #140: report the stored (lowercased) name, not the raw input.
            $user->getUserIdentifier(),
            $email,
        ));

        $event = $stopwatch->stop('add-user-command');

        if ($output->isVerbose()) {
            $this->io->comment(\sprintf(
                'New user database id: %d / Elapsed time: %.2f ms / Consumed memory: %.2f MB',
                $user->getId(),
                $event->getDuration(),
                $event->getMemory() / (1024 ** 2),
            ));
        }

        return Command::SUCCESS;
    }

    private function validateUserData(string $username, string $firstName, string $lastName, string $plainPassword, string $email): void
    {
        // #140: compare case-insensitively — the stored name is lowercased by User::setUsername(),
        // so a byte-wise check would let "MMustermann" through next to an existing "mmustermann"
        // and only fail later on the unique index.
        if (null !== $this->userRepository->findOneByUsernameCaseInsensitive($username)) {
            throw new RuntimeException(\sprintf('There is already a user registered with the "%s" username.', mb_strtolower(trim($username))));
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw new RuntimeException(\sprintf('There is already a user registered with the "%s" email.', $email));
        }

        $this->validator->validatePassword($plainPassword);
        $this->validator->validateEmail($email);
        $this->validator->validateName($firstName);
        $this->validator->validateName($lastName);
    }
}
