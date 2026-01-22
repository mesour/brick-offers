<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Message\AnalyzeLeadMessage;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Service\Analyzer\Issue;
use App\Service\Analyzer\LeadAnalyzerInterface;
use App\Service\Scoring\ScoringServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
        private readonly EntityManagerInterface $em,
        private readonly ScoringServiceInterface $scoringService,
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

        // Parse industry filter
        $industry = null;
        if ($message->industryFilter !== null) {
            $industry = Industry::tryFrom($message->industryFilter);
        }
        $industry = $industry ?? $lead->getIndustry();

        // Filter analyzers by industry
        $analyzersToRun = $this->filterAnalyzers($industry);

        if (empty($analyzersToRun)) {
            $this->logger->warning('No analyzers available for lead', [
                'lead_id' => $message->leadId,
                'industry' => $industry?->value,
            ]);

            return;
        }

        $this->logger->info('Starting lead analysis', [
            'lead_id' => $message->leadId,
            'domain' => $lead->getDomain(),
            'analyzers_count' => count($analyzersToRun),
        ]);

        try {
            $this->analyzeLead($lead, $analyzersToRun, $industry);

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
     * Filter analyzers by industry.
     *
     * @return array<LeadAnalyzerInterface>
     */
    private function filterAnalyzers(?Industry $industry): array
    {
        if ($industry === null) {
            // Without industry, only run universal analyzers
            return array_filter(
                $this->analyzers,
                fn (LeadAnalyzerInterface $a) => $a->getCategory()->isUniversal()
            );
        }

        // With industry, run universal + industry-specific analyzers
        return array_filter($this->analyzers, function (LeadAnalyzerInterface $analyzer) use ($industry): bool {
            $category = $analyzer->getCategory();

            if ($category->isUniversal()) {
                return true;
            }

            return $category->getIndustry() === $industry;
        });
    }

    /**
     * @param array<LeadAnalyzerInterface> $analyzers
     */
    private function analyzeLead(
        \App\Entity\Lead $lead,
        array $analyzers,
        ?Industry $industry,
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
                    $issuesArray = array_map(fn (Issue $issue) => $issue->toStorageArray(), $result->issues);

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
    }
}
