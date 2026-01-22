<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new user account',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'User code (defaults to email prefix)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'User name (defaults to email prefix)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Create as tenant admin (ROLE_ADMIN)')
            ->addOption('admin-code', null, InputOption::VALUE_REQUIRED, 'Admin user code (creates sub-account under this admin)')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Permission template (manager, approver, analyst, full)')
            ->addOption('limits', null, InputOption::VALUE_REQUIRED, 'JSON limits for admin accounts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $isAdmin = $input->getOption('admin');
        $adminCode = $input->getOption('admin-code');
        $template = $input->getOption('template');
        $limitsJson = $input->getOption('limits');

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');

            return Command::FAILURE;
        }

        // Check if email already exists
        $existing = $this->userRepository->findOneBy(['email' => $email]);
        if ($existing !== null) {
            $io->error(sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        // Generate code from email if not provided
        $code = $input->getOption('code');
        if ($code === null) {
            $code = explode('@', $email)[0];
            $code = preg_replace('/[^a-z0-9_-]/', '_', strtolower($code));
        }

        // Check if code already exists
        $existingCode = $this->userRepository->findOneBy(['code' => $code]);
        if ($existingCode !== null) {
            $io->error(sprintf('User with code "%s" already exists.', $code));

            return Command::FAILURE;
        }

        // Generate name from email if not provided
        $name = $input->getOption('name');
        if ($name === null) {
            $name = ucfirst(explode('@', $email)[0]);
        }

        // Find admin account if creating sub-user
        $adminAccount = null;
        if ($adminCode !== null) {
            $adminAccount = $this->userRepository->findOneBy(['code' => $adminCode]);
            if ($adminAccount === null) {
                $io->error(sprintf('Admin user with code "%s" not found.', $adminCode));

                return Command::FAILURE;
            }

            if (!$adminAccount->isAdmin()) {
                $io->error(sprintf('User "%s" is not an admin account.', $adminCode));

                return Command::FAILURE;
            }
        }

        // Cannot be both admin and sub-user
        if ($isAdmin && $adminCode !== null) {
            $io->error('Cannot create an admin account under another admin.');

            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setCode($code);
        $user->setName($name);
        $user->setEmail($email);
        $user->setActive(true);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Set roles
        if ($isAdmin) {
            $user->setRoles([User::ROLE_ADMIN, User::ROLE_USER]);

            // Parse and set limits if provided
            if ($limitsJson !== null) {
                $limits = json_decode($limitsJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $io->error('Invalid JSON for limits.');

                    return Command::FAILURE;
                }
                $user->setLimits($limits);
            }
        } else {
            $user->setRoles([User::ROLE_USER]);

            // Set admin account for sub-user
            if ($adminAccount !== null) {
                $user->setAdminAccount($adminAccount);
            }

            // Apply permission template if provided
            if ($template !== null) {
                if (!isset(User::PERMISSION_TEMPLATES[$template])) {
                    $io->error(sprintf(
                        'Invalid permission template "%s". Available: %s',
                        $template,
                        implode(', ', array_keys(User::PERMISSION_TEMPLATES))
                    ));

                    return Command::FAILURE;
                }
                $user->applyPermissionTemplate($template);
            }
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'User "%s" (%s) created successfully%s.',
            $name,
            $email,
            $isAdmin ? ' as ADMIN' : ($adminAccount !== null ? sprintf(' under admin "%s"', $adminAccount->getName()) : '')
        ));

        $io->table(
            ['Field', 'Value'],
            [
                ['ID', $user->getId()?->toRfc4122()],
                ['Code', $user->getCode()],
                ['Email', $user->getEmail()],
                ['Roles', implode(', ', $user->getRoles())],
                ['Is Admin', $user->isAdmin() ? 'Yes' : 'No'],
                ['Admin Account', $adminAccount?->getName() ?? 'N/A'],
                ['Permissions', $user->isAdmin() ? 'All (admin)' : implode(', ', $user->getPermissions())],
            ]
        );

        return Command::SUCCESS;
    }
}
