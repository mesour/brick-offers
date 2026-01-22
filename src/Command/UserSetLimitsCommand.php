<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:set-limits',
    description: 'Set limits for an admin user (tenant)',
)]
class UserSetLimitsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Admin user code')
            ->addArgument('limits', InputArgument::REQUIRED, 'JSON limits (e.g., {"maxLeadsPerMonth": 1000})');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = $input->getArgument('code');
        $limitsJson = $input->getArgument('limits');

        // Find user
        $user = $this->userRepository->findOneBy(['code' => $code]);
        if ($user === null) {
            $io->error(sprintf('User with code "%s" not found.', $code));

            return Command::FAILURE;
        }

        if (!$user->isAdmin()) {
            $io->error(sprintf('User "%s" is not an admin account. Limits can only be set on admin accounts.', $code));

            return Command::FAILURE;
        }

        // Parse limits
        $limits = json_decode($limitsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Invalid JSON for limits: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        // Merge with existing limits
        $existingLimits = $user->getLimits();
        $newLimits = array_merge($existingLimits, $limits);

        $user->setLimits($newLimits);
        $this->entityManager->flush();

        $io->success(sprintf('Limits updated for user "%s".', $user->getName()));

        $io->table(
            ['Limit', 'Value'],
            array_map(
                fn (string $key, mixed $value) => [$key, is_array($value) ? json_encode($value) : (string) $value],
                array_keys($newLimits),
                array_values($newLimits)
            )
        );

        return Command::SUCCESS;
    }
}
