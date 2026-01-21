<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CompanyRepository;
use App\Service\Company\CompanyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:company:sync-ares',
    description: 'Synchronize ARES data for companies (shared across all users)',
)]
class CompanySyncAresCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly CompanyService $companyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of companies to sync',
                100
            )
            ->addOption(
                'force-refresh',
                'f',
                InputOption::VALUE_NONE,
                'Force refresh all companies, not just outdated ones'
            )
            ->addOption(
                'ico',
                null,
                InputOption::VALUE_REQUIRED,
                'Sync specific IČO (creates company if not exists)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be synced without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $forceRefresh = $input->getOption('force-refresh');
        $specificIco = $input->getOption('ico');
        $dryRun = $input->getOption('dry-run');

        $io->title('ARES Data Synchronization');
        $io->note('Companies are shared across all users');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
        }

        // Handle specific IČO
        if ($specificIco !== null) {
            return $this->syncSingleIco($io, $specificIco, $dryRun);
        }

        // Find companies needing update
        if ($forceRefresh) {
            $io->text('Force refresh mode - syncing all companies');
            $companies = $this->companyRepository->findBy([], ['aresUpdatedAt' => 'ASC'], $limit);
        } else {
            $companies = $this->companyRepository->findNeedingAresUpdate($limit);
        }

        if (empty($companies)) {
            $io->success('No companies need ARES data update');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d companies to sync', count($companies)));

        if ($dryRun) {
            $this->displayCompanies($io, $companies);

            return Command::SUCCESS;
        }

        // Sync companies
        $successCount = 0;
        $failCount = 0;

        $io->progressStart(count($companies));

        foreach ($companies as $company) {
            $result = $this->companyService->refreshAresData($company);

            if ($result) {
                $successCount++;
            } else {
                $failCount++;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Synced %d companies (%d successful, %d failed)',
            count($companies),
            $successCount,
            $failCount
        ));

        return Command::SUCCESS;
    }

    private function syncSingleIco(SymfonyStyle $io, string $ico, bool $dryRun): int
    {
        // Validate IČO
        if (!preg_match('/^\d{8}$/', $ico)) {
            $io->error(sprintf('Invalid IČO format: %s (must be 8 digits)', $ico));

            return Command::FAILURE;
        }

        $io->text(sprintf('Syncing IČO: %s', $ico));

        if ($dryRun) {
            $existing = $this->companyRepository->findByIco($ico);
            $io->note($existing !== null
                ? sprintf('Would refresh: %s', $existing->getName())
                : 'Would create new company from ARES'
            );

            return Command::SUCCESS;
        }

        $company = $this->companyService->findOrCreateByIco($ico);

        if ($company === null) {
            $io->error('Failed to sync IČO - company not found in ARES');

            return Command::FAILURE;
        }

        // If company already existed, force refresh
        if ($company->getAresUpdatedAt() !== null) {
            $this->companyService->refreshAresData($company);
        }

        $io->success(sprintf(
            'Company synced: %s (%s)',
            $company->getName(),
            $company->getIco()
        ));

        $this->displayCompanyDetails($io, $company);

        return Command::SUCCESS;
    }

    /**
     * @param array<\App\Entity\Company> $companies
     */
    private function displayCompanies(SymfonyStyle $io, array $companies): void
    {
        $io->section('Companies to sync (dry run)');

        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [
                $company->getIco(),
                mb_substr($company->getName(), 0, 40),
                $company->getAresUpdatedAt()?->format('Y-m-d H:i') ?? 'never',
                $company->getBusinessStatus() ?? '-',
            ];
        }

        $io->table(['IČO', 'Name', 'Last Updated', 'Status'], $rows);
    }

    private function displayCompanyDetails(SymfonyStyle $io, \App\Entity\Company $company): void
    {
        $io->section('Company Details');

        $io->table([], [
            ['IČO', $company->getIco()],
            ['DIČ', $company->getDic() ?? '-'],
            ['Name', $company->getName()],
            ['Legal Form', $company->getLegalForm() ?? '-'],
            ['Address', $company->getFullAddress() ?? '-'],
            ['Status', $company->getBusinessStatus() ?? '-'],
            ['Updated', $company->getAresUpdatedAt()?->format('Y-m-d H:i:s') ?? '-'],
        ]);
    }
}
