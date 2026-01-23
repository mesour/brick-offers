<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Industry;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:set-industry',
    description: 'Set industry for a user (admin account)',
)]
class UserSetIndustryCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $availableIndustries = implode(', ', array_map(
            fn (Industry $i) => $i->value,
            Industry::cases()
        ));

        $this
            ->addArgument('user', InputArgument::REQUIRED, 'User code or email')
            ->addArgument('industry', InputArgument::OPTIONAL, 'Industry to set (or "none" to clear)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List available industries')
            ->setHelp(<<<HELP
Set industry for an admin user (tenant).
Sub-users inherit industry from their admin account.

Available industries: {$availableIndustries}

Examples:
  <info>bin/console app:user:set-industry admin eshop</info>
  <info>bin/console app:user:set-industry admin real_estate</info>
  <info>bin/console app:user:set-industry admin none</info>
  <info>bin/console app:user:set-industry admin --list</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userIdentifier = $input->getArgument('user');
        $industryValue = $input->getArgument('industry');
        $listOption = $input->getOption('list');

        // Find user by code or email
        $user = $this->userRepository->findOneBy(['code' => $userIdentifier])
            ?? $this->userRepository->findOneBy(['email' => $userIdentifier]);

        if ($user === null) {
            $io->error(sprintf('User "%s" not found.', $userIdentifier));

            return Command::FAILURE;
        }

        // Check if user is admin (industry is set on admin level)
        if (!$user->isAdmin()) {
            $io->error(sprintf(
                'User "%s" is not an admin account. ' .
                'Industry can only be set on admin accounts (sub-users inherit it).',
                $user->getCode()
            ));

            return Command::FAILURE;
        }

        // List available industries
        if ($listOption) {
            $io->title('Available Industries');
            $io->table(
                ['Value', 'Label'],
                array_map(
                    fn (Industry $i) => [$i->value, $i->getLabel()],
                    Industry::cases()
                )
            );

            $current = $user->getIndustry();
            if ($current !== null) {
                $io->info(sprintf('Current industry for "%s": %s (%s)', $user->getName(), $current->value, $current->getLabel()));
            } else {
                $io->info(sprintf('User "%s" has no industry set.', $user->getName()));
            }

            return Command::SUCCESS;
        }

        // If no industry provided, show current
        if ($industryValue === null) {
            $current = $user->getIndustry();
            if ($current !== null) {
                $io->info(sprintf('Current industry: %s (%s)', $current->value, $current->getLabel()));
            } else {
                $io->info('No industry set.');
            }

            return Command::SUCCESS;
        }

        // Clear industry
        if ($industryValue === 'none' || $industryValue === 'null' || $industryValue === '') {
            $user->setIndustry(null);
            $this->entityManager->flush();

            $io->success(sprintf('Industry cleared for user "%s".', $user->getName()));

            return Command::SUCCESS;
        }

        // Set industry
        $industry = Industry::tryFrom($industryValue);
        if ($industry === null) {
            $io->error(sprintf(
                'Unknown industry: "%s". Available: %s',
                $industryValue,
                implode(', ', array_map(fn (Industry $i) => $i->value, Industry::cases()))
            ));

            return Command::FAILURE;
        }

        $user->setIndustry($industry);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Industry set to "%s" (%s) for user "%s".',
            $industry->value,
            $industry->getLabel(),
            $user->getName()
        ));

        return Command::SUCCESS;
    }
}
