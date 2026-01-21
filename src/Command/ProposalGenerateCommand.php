<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Entity\User;
use App\Enum\ProposalType;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Proposal\ProposalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:proposal:generate',
    description: 'Generate proposals for leads',
)]
class ProposalGenerateCommand extends Command
{
    public function __construct(
        private readonly ProposalService $proposalService,
        private readonly LeadRepository $leadRepository,
        private readonly UserRepository $userRepository,
        private readonly AnalysisRepository $analysisRepository,
        private readonly ProposalRepository $proposalRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lead', 'l', InputOption::VALUE_REQUIRED, 'Lead ID to generate proposal for')
            ->addOption('analysis', 'a', InputOption::VALUE_REQUIRED, 'Analysis ID to use for generation')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User code (required)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Proposal type (design_mockup, marketing_audit, etc.)')
            ->addOption('batch', 'b', InputOption::VALUE_NONE, 'Process pending proposals in batch')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit for batch processing', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated without executing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force regeneration even if proposal exists')
            ->addOption('recycle', 'r', InputOption::VALUE_NONE, 'Try to use recycled proposal instead of generating')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($input->getOption('batch')) {
            return $this->processBatch($io, $input, $dryRun);
        }

        // Single proposal generation
        $leadId = $input->getOption('lead');
        $analysisId = $input->getOption('analysis');
        $userCode = $input->getOption('user');

        if (!$leadId && !$analysisId) {
            $io->error('Either --lead or --analysis is required');

            return Command::FAILURE;
        }

        if (!$userCode) {
            $io->error('--user is required');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['code' => $userCode]);
        if (!$user) {
            $io->error(sprintf('User not found: %s', $userCode));

            return Command::FAILURE;
        }

        // Get lead from ID or from analysis
        $lead = null;
        $analysis = null;

        if ($leadId) {
            $lead = $this->leadRepository->find($leadId);
            if (!$lead) {
                $io->error(sprintf('Lead not found: %s', $leadId));

                return Command::FAILURE;
            }
            $analysis = $lead->getLatestAnalysis();
        } elseif ($analysisId) {
            $analysis = $this->analysisRepository->find($analysisId);
            if (!$analysis) {
                $io->error(sprintf('Analysis not found: %s', $analysisId));

                return Command::FAILURE;
            }
            $lead = $analysis->getLead();
        }

        if (!$lead) {
            $io->error('Could not determine lead');

            return Command::FAILURE;
        }

        if (!$analysis) {
            $io->error('No analysis found for lead');

            return Command::FAILURE;
        }

        // Determine proposal type
        $type = null;
        if ($input->getOption('type')) {
            $type = ProposalType::tryFrom($input->getOption('type'));
            if (!$type) {
                $io->error(sprintf('Invalid proposal type: %s', $input->getOption('type')));
                $io->listing(array_map(fn ($t) => $t->value, ProposalType::cases()));

                return Command::FAILURE;
            }
        }

        return $this->generateForLead($io, $lead, $user, $analysis, $type, $input, $dryRun);
    }

    private function generateForLead(
        SymfonyStyle $io,
        Lead $lead,
        User $user,
        mixed $analysis,
        ?ProposalType $type,
        InputInterface $input,
        bool $dryRun
    ): int {
        $industry = $lead->getIndustry();
        $generator = $this->proposalService->getGenerator($industry ?? \App\Enum\Industry::OTHER);

        $io->section('Proposal Generation');
        $io->table([], [
            ['Lead', $lead->getDomain() ?? $lead->getId()?->toRfc4122()],
            ['User', $user->getCode()],
            ['Industry', $industry?->value ?? 'unknown'],
            ['Analysis Score', $analysis->getTotalScore()],
            ['Generator', $generator?->getName() ?? 'none'],
            ['Type', $type?->value ?? $generator?->getProposalType()->value ?? 'auto'],
        ]);

        if (!$generator) {
            $io->warning('No generator available for this industry');

            return Command::FAILURE;
        }

        // Check for recycling
        if ($input->getOption('recycle')) {
            $recycled = $this->proposalService->findAndRecycle(
                $user,
                $lead,
                $industry,
                $type
            );

            if ($recycled) {
                $io->success(sprintf(
                    'Recycled proposal %s from user %s',
                    $recycled->getId()?->toRfc4122(),
                    $recycled->getOriginalUser()?->getCode() ?? 'unknown'
                ));

                return Command::SUCCESS;
            }

            $io->note('No recyclable proposal found, generating new one');
        }

        // Check for existing proposal
        $existingType = $type ?? $generator->getProposalType();
        $existing = $this->proposalRepository->findByLeadAndType($lead, $existingType);

        if ($existing && !$input->getOption('force')) {
            $io->warning(sprintf(
                'Proposal already exists: %s (status: %s). Use --force to regenerate.',
                $existing->getId()?->toRfc4122(),
                $existing->getStatus()->value
            ));

            return Command::SUCCESS;
        }

        // Estimate cost
        $estimate = $generator->estimateCost($analysis);
        $io->note(sprintf(
            'Estimated: ~%d tokens, ~$%.4f USD, ~%ds',
            $estimate->getTotalTokens(),
            $estimate->estimatedCostUsd,
            $estimate->estimatedTimeSeconds
        ));

        if ($dryRun) {
            $io->success('Dry run - no proposal generated');

            return Command::SUCCESS;
        }

        // Generate
        $io->text('Generating proposal...');

        try {
            if ($existing && $input->getOption('force')) {
                // Regenerate existing
                $this->proposalService->generate($existing);
                $proposal = $existing;
            } else {
                // Create and generate new
                $proposal = $this->proposalService->createAndGenerate($lead, $user, $type);
            }

            $io->success(sprintf(
                'Proposal generated: %s (status: %s)',
                $proposal->getId()?->toRfc4122(),
                $proposal->getStatus()->value
            ));

            // Show outputs
            if ($proposal->getOutputs()) {
                $io->section('Outputs');
                foreach ($proposal->getOutputs() as $key => $url) {
                    $io->text(sprintf('  %s: %s', $key, $url));
                }
            }

            // Show AI metadata
            $meta = $proposal->getAiMetadata();
            if (!empty($meta['total_tokens'])) {
                $io->note(sprintf(
                    'Tokens used: %d (in: %d, out: %d)',
                    $meta['total_tokens'] ?? 0,
                    $meta['input_tokens'] ?? 0,
                    $meta['output_tokens'] ?? 0
                ));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Generation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function processBatch(SymfonyStyle $io, InputInterface $input, bool $dryRun): int
    {
        $limit = (int) $input->getOption('limit');

        $io->section('Batch Processing');

        $pending = $this->proposalRepository->findPendingGeneration($limit);

        if (empty($pending)) {
            $io->success('No pending proposals to process');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d pending proposals', count($pending)));

        if ($dryRun) {
            foreach ($pending as $proposal) {
                $io->text(sprintf(
                    '  - %s (%s)',
                    $proposal->getId()?->toRfc4122(),
                    $proposal->getLead()?->getDomain() ?? 'unknown'
                ));
            }
            $io->success('Dry run - no proposals generated');

            return Command::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($pending as $proposal) {
            $io->text(sprintf(
                'Processing: %s (%s)',
                $proposal->getId()?->toRfc4122(),
                $proposal->getLead()?->getDomain() ?? 'unknown'
            ));

            try {
                $this->proposalService->generate($proposal);
                $processed++;
            } catch (\Throwable $e) {
                $io->warning(sprintf('Failed: %s', $e->getMessage()));
                $failed++;
            }
        }

        $io->success(sprintf('Processed: %d, Failed: %d', $processed, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
