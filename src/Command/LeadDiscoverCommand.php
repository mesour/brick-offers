<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Entity\User;
use App\Message\DiscoverLeadsMessage;
use App\Repository\AffiliateRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Discovery\AbstractDiscoverySource;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\DiscoverySourceInterface;
use App\Service\Company\CompanyService;
use App\Service\Discovery\ReferenceDiscoverySource;
use App\Service\Extractor\PageDataExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:lead:discover',
    description: 'Discover leads from various sources',
)]
class LeadDiscoverCommand extends Command
{
    /** @var array<DiscoverySourceInterface> */
    private array $sources = [];

    /**
     * @param iterable<DiscoverySourceInterface> $sources
     */
    public function __construct(
        #[TaggedIterator('app.discovery_source')]
        iterable $sources,
        private readonly LeadRepository $leadRepository,
        private readonly AffiliateRepository $affiliateRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PageDataExtractor $pageDataExtractor,
        private readonly CompanyService $companyService,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();

        foreach ($sources as $source) {
            $this->sources[$source->getSource()->value] = $source;
        }
    }

    protected function configure(): void
    {
        $sourceNames = implode(', ', array_map(
            fn (LeadSource $s) => $s->value,
            LeadSource::cases()
        ));

        $this
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                sprintf('Discovery source (%s)', $sourceNames)
            )
            ->addOption(
                'query',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Search query (can be repeated for multiple queries)'
            )
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Direct URL (for manual source, can be repeated)'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of leads to discover',
                50
            )
            ->addOption(
                'affiliate',
                null,
                InputOption::VALUE_REQUIRED,
                'Affiliate hash for tracking'
            )
            ->addOption(
                'priority',
                'p',
                InputOption::VALUE_REQUIRED,
                'Priority override (1-10)',
                5
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate without saving to database'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for database operations',
                100
            )
            ->addOption(
                'inner-source',
                null,
                InputOption::VALUE_REQUIRED,
                'Inner source for reference_crawler (google, seznam, firmy_cz, etc.)',
                'google'
            )
            ->addOption(
                'extract',
                'x',
                InputOption::VALUE_NONE,
                'Extract contact info (email, phone, IČO) and detect technologies'
            )
            ->addOption(
                'link-company',
                null,
                InputOption::VALUE_NONE,
                'Link leads to Company entities based on IČO (requires --extract, fetches ARES data)'
            )
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'User code (required) - all discovered leads will belong to this user'
            )
            ->addOption(
                'async',
                null,
                InputOption::VALUE_NONE,
                'Dispatch discovery job to the message queue instead of processing synchronously'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse source
        $sourceName = $input->getArgument('source');
        $source = LeadSource::tryFrom($sourceName);

        if ($source === null) {
            $io->error(sprintf('Invalid source "%s". Available: %s', $sourceName, implode(', ', array_map(
                fn (LeadSource $s) => $s->value,
                LeadSource::cases()
            ))));

            return Command::FAILURE;
        }

        // Get discovery source
        $discoverySource = $this->sources[$source->value] ?? null;

        if ($discoverySource === null) {
            $io->error(sprintf('No discovery source implementation for "%s"', $source->value));

            return Command::FAILURE;
        }

        // Configure inner source for reference_crawler
        if ($discoverySource instanceof ReferenceDiscoverySource) {
            $innerSource = $input->getOption('inner-source');

            try {
                $discoverySource->setInnerSource($innerSource);
                $io->note(sprintf('Using inner source: %s', $innerSource));
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
                $io->text('Available inner sources: ' . implode(', ', $discoverySource->getAvailableInnerSources()));

                return Command::FAILURE;
            }
        }

        // Parse options
        $queries = $input->getOption('query');
        $urls = $input->getOption('url');
        $limit = (int) $input->getOption('limit');
        $affiliateHash = $input->getOption('affiliate');
        $priority = (int) $input->getOption('priority');
        $dryRun = $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch-size');
        $extractEnabled = $input->getOption('extract');
        $linkCompany = $input->getOption('link-company');
        $userCode = $input->getOption('user');

        // Get user (required)
        if ($userCode === null) {
            $io->error('User is required. Use --user=<code>');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findByCode($userCode);
        if ($user === null) {
            $io->error(sprintf('User with code "%s" not found', $userCode));

            return Command::FAILURE;
        }

        $io->note(sprintf('Using user: %s (%s)', $user->getName(), $user->getCode()));

        // --link-company requires extraction
        if ($linkCompany && !$extractEnabled) {
            $extractEnabled = true;
            $io->note('Enabling extraction (required for --link-company)');
        }

        // Configure extraction if enabled and source supports it
        if ($extractEnabled && $discoverySource instanceof AbstractDiscoverySource) {
            $discoverySource->setPageDataExtractor($this->pageDataExtractor);
            $discoverySource->setExtractionEnabled(true);
            $io->note('Extraction enabled - will extract contact info and detect technologies');
        }

        if ($linkCompany) {
            $io->note('Company linking enabled - leads with IČO will be linked to Company entities');
        }

        // Validate priority
        $priority = max(1, min(10, $priority));

        // Get affiliate if specified
        $affiliate = null;

        if ($affiliateHash !== null) {
            $affiliate = $this->affiliateRepository->findByHash($affiliateHash);

            if ($affiliate === null) {
                $io->warning(sprintf('Affiliate with hash "%s" not found, continuing without', $affiliateHash));
            }
        }

        // Determine what to discover
        $discoveryInputs = [];

        if ($source === LeadSource::MANUAL) {
            // For manual source, use URLs
            $discoveryInputs = array_merge($queries, $urls);

            if (empty($discoveryInputs)) {
                $io->error('Manual source requires --url or --query with URLs');

                return Command::FAILURE;
            }
        } else {
            // For other sources, use queries
            $discoveryInputs = $queries;

            if (empty($discoveryInputs)) {
                // Crawler doesn't need a query
                if ($source !== LeadSource::CRAWLER) {
                    $io->error('Query required for this source. Use --query');

                    return Command::FAILURE;
                }
                $discoveryInputs = ['']; // Empty query for crawler
            }
        }

        $async = $input->getOption('async');

        $io->title(sprintf('Lead Discovery - %s%s', $source->value, $async ? ' (Async Mode)' : ''));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
        }

        // Async mode - dispatch message to queue
        if ($async) {
            return $this->dispatchAsync(
                $io,
                $source,
                $discoveryInputs,
                $user,
                $limit,
                $affiliateHash,
                $priority,
                $extractEnabled,
                $linkCompany,
                $dryRun,
            );
        }

        // Discover leads
        $allResults = [];

        foreach ($discoveryInputs as $queryInput) {
            $io->section(sprintf('Discovering: %s', $queryInput ?: '(crawler mode)'));

            $results = $discoverySource->discover($queryInput, $limit);
            $io->text(sprintf('Found %d results', count($results)));

            $allResults = array_merge($allResults, $results);

            if (count($allResults) >= $limit) {
                break;
            }
        }

        // Limit total results
        $allResults = array_slice($allResults, 0, $limit);

        if (empty($allResults)) {
            $io->warning('No results found');

            return Command::SUCCESS;
        }

        // Deduplicate by domain
        $allResults = $this->deduplicateByDomain($allResults);
        $io->text(sprintf('%d unique domains after deduplication', count($allResults)));

        // Check existing domains in database (per user)
        $domains = array_map(fn (DiscoveryResult $r) => $r->domain, $allResults);
        $existingDomains = $this->leadRepository->findExistingDomainsForUser($domains, $user);

        $newResults = array_filter(
            $allResults,
            fn (DiscoveryResult $r) => !in_array($r->domain, $existingDomains, true)
        );

        $io->text(sprintf('%d new domains (skipping %d existing)', count($newResults), count($existingDomains)));

        if (empty($newResults)) {
            $io->success('All domains already exist in database for this user');

            return Command::SUCCESS;
        }

        // Save leads
        if ($dryRun) {
            $this->displayResults($io, $newResults);
        } else {
            $savedCount = $this->saveLeads($io, $newResults, $source, $affiliate, $priority, $batchSize, $linkCompany, $user);
            $io->success(sprintf('Saved %d new leads', $savedCount));
        }

        return Command::SUCCESS;
    }

    /**
     * @param string[] $queries
     */
    private function dispatchAsync(
        SymfonyStyle $io,
        LeadSource $source,
        array $queries,
        User $user,
        int $limit,
        ?string $affiliateHash,
        int $priority,
        bool $extractData,
        bool $linkCompany,
        bool $dryRun,
    ): int {
        $userId = $user->getId();
        if ($userId === null) {
            $io->error('User ID is missing');

            return Command::FAILURE;
        }

        $io->section('Dispatch Summary');
        $io->table([], [
            ['Source', $source->value],
            ['Queries', count($queries) . ' query(s)'],
            ['User', $user->getCode()],
            ['Limit', $limit],
            ['Priority', $priority],
            ['Extract Data', $extractData ? 'Yes' : 'No'],
            ['Link Company', $linkCompany ? 'Yes' : 'No'],
        ]);

        if ($dryRun) {
            $io->note('DRY RUN MODE - No message will be dispatched');
            $io->text('Queries:');
            foreach ($queries as $query) {
                $io->text(sprintf('  - %s', $query ?: '(empty)'));
            }

            return Command::SUCCESS;
        }

        $message = new DiscoverLeadsMessage(
            source: $source->value,
            queries: $queries,
            userId: $userId,
            limit: $limit,
            affiliateHash: $affiliateHash,
            priority: $priority,
            extractData: $extractData,
            linkCompany: $linkCompany,
        );

        $this->messageBus->dispatch($message);

        $io->success(sprintf('Dispatched discovery job to the queue (%d queries, source: %s)', count($queries), $source->value));

        return Command::SUCCESS;
    }

    /**
     * @param array<DiscoveryResult> $results
     * @return array<DiscoveryResult>
     */
    private function deduplicateByDomain(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            if (!isset($seen[$result->domain])) {
                $seen[$result->domain] = true;
                $unique[] = $result;
            }
        }

        return $unique;
    }

    /**
     * @param array<DiscoveryResult> $results
     */
    private function displayResults(SymfonyStyle $io, array $results): void
    {
        $io->section('Results (dry run)');

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result->domain,
                substr($result->url, 0, 60) . (strlen($result->url) > 60 ? '...' : ''),
                $result->metadata['title'] ?? '-',
            ];
        }

        $io->table(['Domain', 'URL', 'Title'], $rows);
    }

    /**
     * @param array<DiscoveryResult> $results
     */
    private function saveLeads(
        SymfonyStyle $io,
        array $results,
        LeadSource $source,
        ?\App\Entity\Affiliate $affiliate,
        int $priority,
        int $batchSize,
        bool $linkCompany,
        User $user,
    ): int {
        $savedCount = 0;
        $linkedCount = 0;
        $batch = 0;

        $io->progressStart(count($results));

        foreach ($results as $result) {
            $lead = new Lead();
            $lead->setUser($user);
            $lead->setUrl($result->url);
            $lead->setDomain($result->domain);
            $lead->setSource($source);
            $lead->setStatus(LeadStatus::NEW);
            $lead->setPriority($priority);
            $lead->setMetadata($result->metadata);

            // Populate fields from extracted data
            $this->populateLeadFromExtractedData($lead, $result->metadata);

            if ($affiliate !== null) {
                $lead->setAffiliate($affiliate);
            }

            $this->entityManager->persist($lead);
            $savedCount++;
            $batch++;

            // Link to company if enabled and IČO is present
            if ($linkCompany && $lead->getIco() !== null) {
                // Flush first to ensure lead has ID
                $this->entityManager->flush();
                $company = $this->companyService->linkLeadToCompany($lead);
                if ($company !== null) {
                    $linkedCount++;
                }
            }

            if ($batch >= $batchSize) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $batch = 0;
            }

            $io->progressAdvance();
        }

        // Flush remaining
        if ($batch > 0) {
            $this->entityManager->flush();
        }

        $io->progressFinish();

        if ($linkCompany && $linkedCount > 0) {
            $io->text(sprintf('Linked %d leads to companies', $linkedCount));
        }

        return $savedCount;
    }

    /**
     * Populate Lead entity fields from extracted metadata.
     *
     * @param array<string, mixed> $metadata
     */
    private function populateLeadFromExtractedData(Lead $lead, array $metadata): void
    {
        // Set type and hasWebsite
        $lead->setType(LeadType::WEBSITE);
        $lead->setHasWebsite(true);

        // Set email from extracted emails (first one is highest priority)
        if (!empty($metadata['extracted_emails'])) {
            $lead->setEmail($metadata['extracted_emails'][0]);
        }

        // Set phone from extracted phones (first one)
        if (!empty($metadata['extracted_phones'])) {
            $lead->setPhone($metadata['extracted_phones'][0]);
        }

        // Set IČO if extracted
        if (!empty($metadata['extracted_ico'])) {
            $lead->setIco($metadata['extracted_ico']);
        }

        // Set company name if available
        if (!empty($metadata['extracted_company_name'])) {
            $lead->setCompanyName($metadata['extracted_company_name']);
        } elseif (!empty($metadata['business_name'])) {
            // Fallback to business name from catalog sources (e.g., Firmy.cz)
            $lead->setCompanyName($metadata['business_name']);
        }

        // Set address if extracted
        if (!empty($metadata['extracted_address'])) {
            $lead->setAddress($metadata['extracted_address']);
        }

        // Set detected CMS
        if (!empty($metadata['detected_cms'])) {
            $lead->setDetectedCms($metadata['detected_cms']);
        }

        // Set detected technologies
        if (!empty($metadata['detected_technologies'])) {
            $lead->setDetectedTechnologies($metadata['detected_technologies']);
        }

        // Set social media
        if (!empty($metadata['social_media'])) {
            $lead->setSocialMedia($metadata['social_media']);
        }
    }
}
