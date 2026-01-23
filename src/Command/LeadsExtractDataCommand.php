<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Repository\LeadRepository;
use App\Service\Company\CompanyService;
use App\Service\Extractor\PageDataExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:leads:extract-data',
    description: 'Extract contact data (emails, phones, ICO) from existing leads',
)]
class LeadsExtractDataCommand extends Command
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly PageDataExtractor $extractor,
        private readonly CompanyService $companyService,
        private readonly EntityManagerInterface $em,
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
                'Extract data for specific lead by UUID'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Extract data for all leads without extracted data'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force re-extraction even if data already exists'
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
                'Show what would be extracted without saving'
            )
            ->addOption(
                'link-company',
                'c',
                InputOption::VALUE_NONE,
                'Link leads to Company entities via IČO (fetches data from ARES)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $leadId = $input->getOption('lead-id');
        $all = $input->getOption('all');
        $force = $input->getOption('force');
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');
        $linkCompany = $input->getOption('link-company');

        if ($leadId === null && !$all) {
            $io->error('Specify either --lead-id=UUID or --all');

            return Command::FAILURE;
        }

        $io->title('Lead Data Extraction');

        if ($dryRun) {
            $io->note('DRY RUN - No changes will be saved');
        }

        if ($linkCompany) {
            $io->note('Company linking enabled - will fetch data from ARES for new IČOs');
        }

        if ($leadId !== null) {
            return $this->extractForLead($io, $leadId, $force, $dryRun, $linkCompany);
        }

        return $this->extractForAll($io, $force, $limit, $dryRun, $linkCompany);
    }

    private function extractForLead(SymfonyStyle $io, string $leadId, bool $force, bool $dryRun, bool $linkCompany): int
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

        $result = $this->extractDataForLead($lead, $force, $dryRun);

        if ($result['skipped']) {
            $io->warning(sprintf('Skipped %s - already has data (use --force to re-extract)', $lead->getDomain()));

            return Command::SUCCESS;
        }

        if ($result['error'] !== null) {
            $io->error(sprintf('Failed to extract from %s: %s', $lead->getDomain(), $result['error']));

            return Command::FAILURE;
        }

        $this->displayResult($io, $lead, $result);

        if (!$dryRun) {
            // Link to company if IČO was found and linking is enabled
            if ($linkCompany && $lead->getIco() !== null && $lead->getCompany() === null) {
                $company = $this->companyService->linkLeadToCompany($lead);
                if ($company !== null) {
                    $io->note(sprintf('Linked to company: %s (IČO %s)', $company->getName(), $company->getIco()));
                }
            }

            $this->em->flush();
            $io->success('Data saved successfully');
        }

        return Command::SUCCESS;
    }

    private function extractForAll(SymfonyStyle $io, bool $force, int $limit, bool $dryRun, bool $linkCompany): int
    {
        // Get leads to process
        $qb = $this->leadRepository->createQueryBuilder('l')
            ->where('l.hasWebsite = true')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        if (!$force) {
            // Only leads without extracted email
            $qb->andWhere('l.email IS NULL');
        }

        $leads = $qb->getQuery()->getResult();

        if (empty($leads)) {
            $io->success('No leads to process');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Processing %d leads...', count($leads)));

        $stats = [
            'processed' => 0,
            'extracted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'linked' => 0,
        ];

        $io->progressStart(count($leads));

        foreach ($leads as $lead) {
            $result = $this->extractDataForLead($lead, $force, $dryRun);

            $stats['processed']++;

            if ($result['skipped']) {
                $stats['skipped']++;
            } elseif ($result['error'] !== null) {
                $stats['failed']++;
                $this->logger->warning('Extraction failed', [
                    'domain' => $lead->getDomain(),
                    'error' => $result['error'],
                ]);
            } else {
                $stats['extracted']++;

                // Link to company if IČO was found and linking is enabled
                if ($linkCompany && !$dryRun && $lead->getIco() !== null && $lead->getCompany() === null) {
                    $this->logger->debug('Attempting to link company', [
                        'domain' => $lead->getDomain(),
                        'ico' => $lead->getIco(),
                    ]);
                    $company = $this->companyService->linkLeadToCompany($lead);
                    if ($company !== null) {
                        $stats['linked']++;
                        $this->logger->info('Linked to company', [
                            'domain' => $lead->getDomain(),
                            'ico' => $lead->getIco(),
                            'company_name' => $company->getName(),
                        ]);
                    } else {
                        $this->logger->warning('Failed to link company', [
                            'domain' => $lead->getDomain(),
                            'ico' => $lead->getIco(),
                        ]);
                    }
                } elseif ($linkCompany && !$dryRun) {
                    $this->logger->debug('Company linking skipped', [
                        'domain' => $lead->getDomain(),
                        'has_ico' => $lead->getIco() !== null,
                        'ico' => $lead->getIco(),
                        'has_company' => $lead->getCompany() !== null,
                    ]);
                }

                // Log what was found
                if (!empty($result['emails']) || !empty($result['phones'])) {
                    $this->logger->info('Extracted data', [
                        'domain' => $lead->getDomain(),
                        'emails' => $result['emails'],
                        'phones' => $result['phones'],
                        'ico' => $result['ico'],
                    ]);
                }
            }

            $io->progressAdvance();

            // Flush in batches
            if ($stats['processed'] % 20 === 0 && !$dryRun) {
                $this->em->flush();
            }

            // Small delay to avoid overwhelming servers
            usleep(200000); // 200ms
        }

        $io->progressFinish();

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->section('Results');
        $io->definitionList(
            ['Processed' => $stats['processed']],
            ['Extracted' => $stats['extracted']],
            ['Linked to company' => $stats['linked']],
            ['Skipped' => $stats['skipped']],
            ['Failed' => $stats['failed']],
        );

        $io->success('Extraction complete');

        return Command::SUCCESS;
    }

    /**
     * @return array{skipped: bool, error: string|null, emails: array<string>, phones: array<string>, ico: string|null, companyName: string|null}
     */
    private function extractDataForLead(Lead $lead, bool $force, bool $dryRun): array
    {
        $result = [
            'skipped' => false,
            'error' => null,
            'emails' => [],
            'phones' => [],
            'ico' => null,
            'companyName' => null,
        ];

        // Skip if already has data and not forcing
        if (!$force && $lead->getEmail() !== null) {
            $result['skipped'] = true;

            return $result;
        }

        $url = $lead->getUrl();
        if ($url === null) {
            $result['error'] = 'No URL';

            return $result;
        }

        try {
            $pageData = $this->extractor->extractFromUrl($url);

            if ($pageData === null) {
                $result['error'] = 'Failed to fetch URL';

                return $result;
            }

            $result['emails'] = $pageData->emails;
            $result['phones'] = $pageData->phones;
            $result['ico'] = $pageData->ico;
            $result['companyName'] = $pageData->companyName;

            if (!$dryRun) {
                // Update lead fields (overwrite if force mode or field is null)
                if (!empty($pageData->emails) && ($force || $lead->getEmail() === null)) {
                    $lead->setEmail($pageData->emails[0]);
                }

                if (!empty($pageData->phones) && ($force || $lead->getPhone() === null)) {
                    $lead->setPhone($pageData->phones[0]);
                }

                if ($pageData->ico !== null && ($force || $lead->getIco() === null)) {
                    $lead->setIco($pageData->ico);
                }

                if ($pageData->companyName !== null && ($force || $lead->getCompanyName() === null)) {
                    $lead->setCompanyName($pageData->companyName);
                }

                if ($pageData->cms !== null && ($force || $lead->getDetectedCms() === null)) {
                    $lead->setDetectedCms($pageData->cms);
                }

                if (!empty($pageData->technologies) && ($force || $lead->getDetectedTechnologies() === null)) {
                    $lead->setDetectedTechnologies($pageData->technologies);
                }

                if (!empty($pageData->socialMedia) && ($force || $lead->getSocialMedia() === null)) {
                    $lead->setSocialMedia($pageData->socialMedia);
                }

                // Merge into metadata
                $metadata = $lead->getMetadata();
                $metadata = array_merge($metadata, $pageData->toMetadata());
                $lead->setMetadata($metadata);
            }

            return $result;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }
    }

    /**
     * @param array{skipped: bool, error: string|null, emails: array<string>, phones: array<string>, ico: string|null, companyName: string|null} $result
     */
    private function displayResult(SymfonyStyle $io, Lead $lead, array $result): void
    {
        $io->section(sprintf('Extracted data for %s', $lead->getDomain()));

        $rows = [];

        if (!empty($result['emails'])) {
            $rows[] = ['Emails', implode(', ', $result['emails'])];
        }

        if (!empty($result['phones'])) {
            $rows[] = ['Phones', implode(', ', $result['phones'])];
        }

        if ($result['ico'] !== null) {
            $rows[] = ['ICO', $result['ico']];
        }

        if ($result['companyName'] !== null) {
            $rows[] = ['Company', $result['companyName']];
        }

        if (empty($rows)) {
            $io->note('No contact data found on this page');
        } else {
            $io->table(['Field', 'Value'], $rows);
        }
    }
}
