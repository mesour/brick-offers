<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LeadRepository;
use App\Service\Company\CompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:leads:link-company',
    description: 'Link existing leads to Company entities via IČO (fetches data from ARES)',
)]
class LeadsLinkCompanyCommand extends Command
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly CompanyService $companyService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'lead-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Link specific lead by UUID'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Link all leads with IČO that are not yet linked to a company'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit number of leads to process',
                100
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be linked without saving'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $leadId = $input->getOption('lead-id');
        $all = $input->getOption('all');
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');

        if ($leadId === null && !$all) {
            $io->error('Specify either --lead-id=UUID or --all');

            return Command::FAILURE;
        }

        $io->title('Lead → Company Linking');

        if ($dryRun) {
            $io->note('DRY RUN - No changes will be saved');
        }

        if ($leadId !== null) {
            return $this->linkForLead($io, $leadId, $dryRun);
        }

        return $this->linkForAll($io, $limit, $dryRun);
    }

    private function linkForLead(SymfonyStyle $io, string $leadId, bool $dryRun): int
    {
        try {
            $uuid = Uuid::fromString($leadId);
        } catch (\InvalidArgumentException $e) {
            $io->error(sprintf('Invalid UUID: %s', $leadId));

            return Command::FAILURE;
        }

        $lead = $this->leadRepository->find($uuid);

        if ($lead === null) {
            $io->error(sprintf('Lead not found: %s', $leadId));

            return Command::FAILURE;
        }

        if ($lead->getIco() === null) {
            $io->error(sprintf('Lead %s has no IČO', $lead->getDomain()));

            return Command::FAILURE;
        }

        if ($lead->getCompany() !== null) {
            $io->warning(sprintf('Lead %s is already linked to company: %s', $lead->getDomain(), $lead->getCompany()->getName()));

            return Command::SUCCESS;
        }

        $io->note(sprintf('Linking %s (IČO: %s)...', $lead->getDomain(), $lead->getIco()));

        if ($dryRun) {
            $io->success('Would link to company (dry run)');

            return Command::SUCCESS;
        }

        $company = $this->companyService->linkLeadToCompany($lead);

        if ($company === null) {
            $io->error(sprintf('Failed to link lead to company (invalid IČO format or ARES error)'));

            return Command::FAILURE;
        }

        $io->success(sprintf('Linked to company: %s (IČO %s)', $company->getName(), $company->getIco()));

        return Command::SUCCESS;
    }

    private function linkForAll(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        // Get leads with IČO but no company
        $qb = $this->leadRepository->createQueryBuilder('l')
            ->where('l.ico IS NOT NULL')
            ->andWhere('l.company IS NULL')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        $leads = $qb->getQuery()->getResult();

        if (empty($leads)) {
            $io->success('No leads to link (all leads with IČO are already linked to companies)');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Found %d leads with IČO but no company link...', count($leads)));

        $stats = [
            'processed' => 0,
            'linked' => 0,
            'failed' => 0,
        ];

        $io->progressStart(count($leads));

        foreach ($leads as $lead) {
            $stats['processed']++;

            if ($dryRun) {
                $io->progressAdvance();
                $stats['linked']++;
                continue;
            }

            $company = $this->companyService->linkLeadToCompany($lead);

            if ($company !== null) {
                $stats['linked']++;
                $this->logger->info('Linked to company', [
                    'domain' => $lead->getDomain(),
                    'ico' => $lead->getIco(),
                    'company_name' => $company->getName(),
                ]);
            } else {
                $stats['failed']++;
                $this->logger->warning('Failed to link company', [
                    'domain' => $lead->getDomain(),
                    'ico' => $lead->getIco(),
                ]);
            }

            $io->progressAdvance();

            // Small delay to avoid overwhelming ARES API
            usleep(300000); // 300ms
        }

        $io->progressFinish();

        $io->section('Results');
        $io->definitionList(
            ['Processed' => $stats['processed']],
            ['Linked' => $stats['linked']],
            ['Failed' => $stats['failed']],
        );

        $io->success('Linking complete');

        return Command::SUCCESS;
    }
}
