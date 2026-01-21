<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Repository\AffiliateRepository;
use App\Repository\LeadRepository;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\DiscoverySourceInterface;
use App\Service\Discovery\ReferenceDiscoverySource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

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
        private readonly EntityManagerInterface $entityManager,
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

        $io->title(sprintf('Lead Discovery - %s', $source->value));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
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

        // Check existing domains in database
        $domains = array_map(fn (DiscoveryResult $r) => $r->domain, $allResults);
        $existingDomains = $this->leadRepository->findExistingDomains($domains);

        $newResults = array_filter(
            $allResults,
            fn (DiscoveryResult $r) => !in_array($r->domain, $existingDomains, true)
        );

        $io->text(sprintf('%d new domains (skipping %d existing)', count($newResults), count($existingDomains)));

        if (empty($newResults)) {
            $io->success('All domains already exist in database');

            return Command::SUCCESS;
        }

        // Save leads
        if ($dryRun) {
            $this->displayResults($io, $newResults);
        } else {
            $savedCount = $this->saveLeads($io, $newResults, $source, $affiliate, $priority, $batchSize);
            $io->success(sprintf('Saved %d new leads', $savedCount));
        }

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
    ): int {
        $savedCount = 0;
        $batch = 0;

        $io->progressStart(count($results));

        foreach ($results as $result) {
            $lead = new Lead();
            $lead->setUrl($result->url);
            $lead->setDomain($result->domain);
            $lead->setSource($source);
            $lead->setStatus(LeadStatus::NEW);
            $lead->setPriority($priority);
            $lead->setMetadata($result->metadata);

            if ($affiliate !== null) {
                $lead->setAffiliate($affiliate);
            }

            $this->entityManager->persist($lead);
            $savedCount++;
            $batch++;

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

        return $savedCount;
    }
}
