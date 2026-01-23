<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DemandSignal;
use App\Entity\User;
use App\Enum\DemandSignalSource;
use App\Repository\DemandSignalRepository;
use App\Service\Demand\DemandSignalResult;
use App\Service\Demand\DemandSignalSourceInterface;
use App\Service\Demand\DemandSignalSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:demand:monitor',
    description: 'Monitor demand signals from various sources (job portals, tenders, RFP platforms)',
)]
class DemandMonitorCommand extends Command
{
    /** @var iterable<DemandSignalSourceInterface> */
    private iterable $sources;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DemandSignalRepository $demandSignalRepository,
        private readonly DemandSignalSubscriptionService $subscriptionService,
        iterable $sources,
    ) {
        parent::__construct();
        $this->sources = $sources;
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Source to monitor: epoptavka, nen, jobs_cz, prace_cz, all')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum signals to discover per source', 50)
            ->addOption('query', 'q', InputOption::VALUE_REQUIRED, 'Search query')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Category filter')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Region filter')
            ->addOption('min-value', null, InputOption::VALUE_REQUIRED, 'Minimum value/budget filter')
            ->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'User ID to associate signals with')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist signals to database')
            ->addOption('expire-old', null, InputOption::VALUE_NONE, 'Expire signals with passed deadlines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceOption = $input->getOption('source');
        $limit = (int) $input->getOption('limit');
        $query = $input->getOption('query');
        $category = $input->getOption('category');
        $region = $input->getOption('region');
        $minValue = $input->getOption('min-value') !== null ? (float) $input->getOption('min-value') : null;
        $userId = $input->getOption('user-id');
        $dryRun = $input->getOption('dry-run');
        $expireOld = $input->getOption('expire-old');

        // Expire old signals if requested
        if ($expireOld) {
            $expired = $this->demandSignalRepository->expireOldSignals();
            $io->note("Expired {$expired} signals with passed deadlines");
        }

        // Get user
        $user = null;
        if ($userId !== null) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if ($user === null) {
                $io->error("User with ID {$userId} not found");

                return Command::FAILURE;
            }
        } else {
            // Get default/first user
            $user = $this->entityManager->getRepository(User::class)->findOneBy([]);
            if ($user === null) {
                $io->error('No users found. Please create a user first.');

                return Command::FAILURE;
            }
        }

        // Determine which sources to use
        $sourcesToUse = $this->getSourcesToUse($sourceOption);

        if (empty($sourcesToUse)) {
            $io->error('No valid sources specified. Use: epoptavka, nen, jobs_cz, prace_cz, or all');

            return Command::FAILURE;
        }

        $options = array_filter([
            'query' => $query,
            'category' => $category,
            'region' => $region,
            'minValue' => $minValue,
        ]);

        $io->title('Demand Signal Monitor');
        $io->text("Sources: " . implode(', ', array_map(fn ($s) => $s->value, $sourcesToUse)));
        $io->text("Limit per source: {$limit}");
        if ($dryRun) {
            $io->note('DRY RUN - signals will not be persisted');
        }

        $totalNew = 0;
        $totalDuplicate = 0;
        $totalFailed = 0;
        $totalSubscriptions = 0;

        /** @var DemandSignal[] $newSignals */
        $newSignals = [];

        foreach ($sourcesToUse as $sourceType) {
            $source = $this->getSourceImplementation($sourceType);
            if ($source === null) {
                $io->warning("No implementation found for source: {$sourceType->value}");
                continue;
            }

            $io->section("Discovering from: {$sourceType->getLabel()}");

            try {
                $results = $source->discover($options, $limit);
                $io->text("Found " . count($results) . " signals");

                foreach ($results as $result) {
                    // Check for duplicates
                    $existing = $this->demandSignalRepository->findByExternalId($result->source, $result->externalId);
                    if ($existing !== null) {
                        $totalDuplicate++;
                        if ($output->isVerbose()) {
                            $io->text("  - DUPLICATE: {$result->title}");
                        }
                        continue;
                    }

                    // Create new signal
                    $signal = $this->createSignalFromResult($result, $user);

                    if (!$dryRun) {
                        $this->entityManager->persist($signal);
                        $newSignals[] = $signal;
                    }

                    $totalNew++;

                    if ($output->isVerbose()) {
                        $io->text("  + NEW: {$result->title}");
                        if ($result->value !== null) {
                            $io->text("    Value: " . number_format($result->value, 0, ',', ' ') . " {$result->currency}");
                        }
                    }
                }

                if (!$dryRun) {
                    $this->entityManager->flush();
                }

            } catch (\Throwable $e) {
                $io->error("Failed to discover from {$sourceType->value}: " . $e->getMessage());
                $totalFailed++;
            }
        }

        // Final flush
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Create subscriptions for new signals
        if (!$dryRun && !empty($newSignals)) {
            $io->section('Creating subscriptions for matching filters');

            foreach ($newSignals as $signal) {
                $subscriptions = $this->subscriptionService->createSubscriptionsForSignal($signal);
                $subscriptionCount = count($subscriptions);
                $totalSubscriptions += $subscriptionCount;

                if ($output->isVerbose() && $subscriptionCount > 0) {
                    $io->text("  {$signal->getTitle()}: {$subscriptionCount} subscription(s)");
                }
            }

            $this->entityManager->flush();
        }

        // Summary
        $io->newLine();
        $io->success([
            "Discovery complete!",
            "New signals: {$totalNew}",
            "Subscriptions created: {$totalSubscriptions}",
            "Duplicates skipped: {$totalDuplicate}",
            "Failed sources: {$totalFailed}",
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return DemandSignalSource[]
     */
    private function getSourcesToUse(?string $option): array
    {
        if ($option === null || $option === 'all') {
            return [
                DemandSignalSource::EPOPTAVKA,
                DemandSignalSource::NEN,
                DemandSignalSource::JOBS_CZ,
            ];
        }

        $source = DemandSignalSource::tryFrom($option);
        if ($source !== null) {
            return [$source];
        }

        return [];
    }

    private function getSourceImplementation(DemandSignalSource $source): ?DemandSignalSourceInterface
    {
        foreach ($this->sources as $implementation) {
            if ($implementation->supports($source)) {
                return $implementation;
            }
        }

        return null;
    }

    private function createSignalFromResult(DemandSignalResult $result, User $user): DemandSignal
    {
        $signal = new DemandSignal();
        $signal->setUser($user);
        $signal->setSource($result->source);
        $signal->setSignalType($result->type);
        $signal->setExternalId($result->externalId);
        $signal->setTitle($result->title);
        $signal->setDescription($result->description);
        $signal->setCompanyName($result->companyName);
        $signal->setIco($result->ico);
        $signal->setContactEmail($result->contactEmail);
        $signal->setContactPhone($result->contactPhone);
        $signal->setContactPerson($result->contactPerson);

        if ($result->value !== null) {
            $signal->setValue((string) $result->value);
        }
        if ($result->valueMax !== null) {
            $signal->setValueMax((string) $result->valueMax);
        }

        $signal->setCurrency($result->currency);
        $signal->setIndustry($result->industry);
        $signal->setLocation($result->location);
        $signal->setRegion($result->region);
        $signal->setDeadline($result->deadline);
        $signal->setPublishedAt($result->publishedAt);
        $signal->setSourceUrl($result->sourceUrl);
        $signal->setRawData($result->rawData);

        return $signal;
    }
}
