<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Entity\DiscoveryProfile;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Message\AnalyzeLeadMessage;
use App\Message\TakeScreenshotMessage;
use App\Repository\AnalysisRepository;
use App\Repository\DiscoveryProfileRepository;
use App\Repository\LeadRepository;
use App\Service\Analyzer\Issue;
use App\Service\Analyzer\LeadAnalyzerInterface;
use App\Service\Scoring\ScoringServiceInterface;
use App\Service\Snapshot\SnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler for analyzing leads asynchronously.
 */
#[AsMessageHandler]
final class AnalyzeLeadMessageHandler
{
    /** @var array<LeadAnalyzerInterface> */
    private array $analyzers = [];

    /**
     * @param iterable<LeadAnalyzerInterface> $analyzers
     */
    public function __construct(
        #[TaggedIterator('app.lead_analyzer')]
        iterable $analyzers,
        private readonly LeadRepository $leadRepository,
        private readonly AnalysisRepository $analysisRepository,
        private readonly DiscoveryProfileRepository $profileRepository,
        private readonly EntityManagerInterface $em,
        private readonly ScoringServiceInterface $scoringService,
        private readonly SnapshotService $snapshotService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        // Sort analyzers by priority
        $analyzerArray = iterator_to_array($analyzers);
        usort($analyzerArray, fn (LeadAnalyzerInterface $a, LeadAnalyzerInterface $b) => $a->getPriority() <=> $b->getPriority());
        $this->analyzers = $analyzerArray;
    }

    public function __invoke(AnalyzeLeadMessage $message): void
    {
        $lead = $this->leadRepository->find($message->leadId);

        if ($lead === null) {
            $this->logger->error('Lead not found for analysis', [
                'lead_id' => $message->leadId,
            ]);

            return;
        }

        // Check if already analyzed (unless reanalyze flag is set)
        if (!$message->reanalyze) {
            $existingAnalysis = $this->analysisRepository->findLatestByLead($lead);
            if ($existingAnalysis !== null && $existingAnalysis->getStatus() === AnalysisStatus::COMPLETED) {
                $this->logger->info('Lead already analyzed, skipping', [
                    'lead_id' => $message->leadId,
                ]);

                return;
            }
        }

        // Get discovery profile if specified
        $profile = null;
        if ($message->profileId !== null) {
            $profile = $this->profileRepository->find($message->profileId);
        }
        // Try to get profile from lead if not specified in message
        if ($profile === null) {
            $profile = $lead->getDiscoveryProfile();
        }

        // Parse industry filter
        $industry = null;
        if ($message->industryFilter !== null) {
            $industry = Industry::tryFrom($message->industryFilter);
        }
        // Fallback to profile industry, then lead industry
        $industry = $industry ?? $profile?->getIndustry() ?? $lead->getIndustry();

        // Filter analyzers by industry and profile config
        $analyzersToRun = $this->filterAnalyzers($industry, $profile);

        if (empty($analyzersToRun)) {
            $this->logger->warning('No analyzers available for lead', [
                'lead_id' => $message->leadId,
                'industry' => $industry?->value,
                'profile_id' => $message->profileId?->toRfc4122(),
            ]);

            return;
        }

        $this->logger->info('Starting lead analysis', [
            'lead_id' => $message->leadId,
            'domain' => $lead->getDomain(),
            'analyzers_count' => count($analyzersToRun),
            'profile_id' => $profile?->getId()?->toRfc4122(),
        ]);

        try {
            $this->analyzeLead($lead, $analyzersToRun, $industry, $profile);

            $this->logger->info('Lead analysis completed', [
                'lead_id' => $message->leadId,
                'domain' => $lead->getDomain(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Lead analysis failed', [
                'lead_id' => $message->leadId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Filter analyzers by industry and profile configuration.
     *
     * @return array<LeadAnalyzerInterface>
     */
    private function filterAnalyzers(?Industry $industry, ?DiscoveryProfile $profile = null): array
    {
        $filtered = [];

        foreach ($this->analyzers as $analyzer) {
            $category = $analyzer->getCategory();

            // Check if analyzer is enabled in profile config
            if ($profile !== null && !$profile->isAnalyzerEnabled($category->value)) {
                continue;
            }

            // Industry filtering
            if ($industry === null) {
                // Without industry, only run universal analyzers
                if (!$category->isUniversal()) {
                    continue;
                }
            } else {
                // With industry, run universal + industry-specific analyzers
                if (!$category->isUniversal() && $category->getIndustry() !== $industry) {
                    continue;
                }
            }

            $filtered[] = $analyzer;
        }

        // Re-sort by profile priorities if available
        if ($profile !== null) {
            usort($filtered, function (LeadAnalyzerInterface $a, LeadAnalyzerInterface $b) use ($profile): int {
                $configA = $profile->getAnalyzerConfig($a->getCategory()->value);
                $configB = $profile->getAnalyzerConfig($b->getCategory()->value);

                $priorityA = $configA['priority'] ?? $a->getPriority();
                $priorityB = $configB['priority'] ?? $b->getPriority();

                return $priorityA <=> $priorityB;
            });
        }

        return $filtered;
    }

    /**
     * @param array<LeadAnalyzerInterface> $analyzers
     */
    private function analyzeLead(
        \App\Entity\Lead $lead,
        array $analyzers,
        ?Industry $industry,
        ?DiscoveryProfile $profile = null,
    ): void {
        // Get previous analysis for delta calculation
        $previousAnalysis = $this->analysisRepository->findLatestByLead($lead);

        // Calculate sequence number
        $sequenceNumber = 1;
        if ($previousAnalysis !== null) {
            $sequenceNumber = $previousAnalysis->getSequenceNumber() + 1;
        }

        // Create new analysis
        $analysis = new Analysis();
        $analysis->setLead($lead);
        $analysis->setIndustry($industry ?? $lead->getIndustry());
        $analysis->setSequenceNumber($sequenceNumber);
        $analysis->setPreviousAnalysis($previousAnalysis);
        $analysis->markAsRunning();

        $this->em->persist($analysis);
        $this->em->flush();

        // Run each analyzer
        foreach ($analyzers as $analyzer) {
            $category = $analyzer->getCategory();

            // Create result for this analyzer
            $analysisResult = new AnalysisResult();
            $analysisResult->setCategory($category);
            $analysisResult->markAsRunning();
            $analysis->addResult($analysisResult);

            try {
                $result = $analyzer->analyze($lead);

                if ($result->success) {
                    // Filter out ignored issue codes if profile specifies them
                    $issues = $result->issues;
                    if ($profile !== null) {
                        $ignoreCodes = $profile->getIgnoreCodes($category->value);
                        if (!empty($ignoreCodes)) {
                            $issues = array_filter(
                                $issues,
                                fn (Issue $issue) => !in_array($issue->code, $ignoreCodes, true)
                            );
                        }
                    }

                    $issuesArray = array_map(fn (Issue $issue) => $issue->toStorageArray(), $issues);

                    $analysisResult->setRawData($result->rawData);
                    $analysisResult->setIssues($issuesArray);
                    $analysisResult->setScore($result->getScore());
                    $analysisResult->markAsCompleted();

                    // Handle e-shop detection
                    if ($category === IssueCategory::ESHOP_DETECTION && isset($result->rawData['isEshop'])) {
                        $analysis->setIsEshop((bool) $result->rawData['isEshop']);
                    }
                } else {
                    $analysisResult->markAsFailed($result->errorMessage ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $analysisResult->markAsFailed($e->getMessage());
            }
        }

        // Calculate total score and finalize analysis
        $analysis->calculateTotalScore();

        if ($analysis->getFailedResultsCount() === count($analyzers)) {
            $analysis->markAsFailed();
        } else {
            $analysis->markAsCompleted();
        }

        // Calculate delta compared to previous analysis
        $analysis->calculateDelta();

        // Determine lead status
        $newStatus = $this->scoringService->determineLeadStatus($analysis);

        // Update lead
        $lead->setStatus($newStatus);
        $lead->setAnalyzedAt(new \DateTimeImmutable());
        $lead->setLastAnalyzedAt(new \DateTimeImmutable());
        $lead->setLatestAnalysis($analysis);
        $lead->incrementAnalysisCount();

        // Set industry on lead if provided and not already set
        if ($industry !== null && $lead->getIndustry() === null) {
            $lead->setIndustry($industry);
        }

        $this->em->flush();

        // Create data snapshot for trending/history
        if ($analysis->getStatus() === AnalysisStatus::COMPLETED) {
            $this->snapshotService->createSnapshot($analysis);
            $this->em->flush();

            $this->logger->info('Created analysis snapshot', [
                'lead_id' => $lead->getId()?->toRfc4122(),
                'domain' => $lead->getDomain(),
            ]);
        }

        // Dispatch screenshot capture asynchronously
        $leadId = $lead->getId();
        if ($leadId !== null) {
            $this->messageBus->dispatch(new TakeScreenshotMessage($leadId));

            $this->logger->info('Dispatched screenshot capture', [
                'lead_id' => $leadId->toRfc4122(),
                'domain' => $lead->getDomain(),
            ]);
        }
    }
}
