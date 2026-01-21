<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Entity\User;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Offer\OfferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:offer:generate',
    description: 'Generate email offers for leads',
)]
class OfferGenerateCommand extends Command
{
    public function __construct(
        private readonly OfferService $offerService,
        private readonly LeadRepository $leadRepository,
        private readonly UserRepository $userRepository,
        private readonly ProposalRepository $proposalRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lead', 'l', InputOption::VALUE_REQUIRED, 'Lead ID to generate offer for')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User code (required)')
            ->addOption('proposal', 'p', InputOption::VALUE_REQUIRED, 'Proposal ID to include')
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Override recipient email')
            ->addOption('template', 't', InputOption::VALUE_REQUIRED, 'Template name to use')
            ->addOption('batch', 'b', InputOption::VALUE_NONE, 'Process multiple leads')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit for batch processing', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated without executing')
            ->addOption('send', 's', InputOption::VALUE_NONE, 'Send immediately after generation (requires approval first)')
            ->addOption('skip-ai', null, InputOption::VALUE_NONE, 'Skip AI personalization')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($input->getOption('batch')) {
            return $this->processBatch($io, $input, $dryRun);
        }

        // Single offer generation
        $leadId = $input->getOption('lead');
        $userCode = $input->getOption('user');

        if (!$leadId) {
            $io->error('--lead is required');

            return Command::FAILURE;
        }

        if (!$userCode) {
            $io->error('--user is required');

            return Command::FAILURE;
        }

        $lead = $this->leadRepository->find($leadId);
        if (!$lead) {
            $io->error(sprintf('Lead not found: %s', $leadId));

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['code' => $userCode]);
        if (!$user) {
            $io->error(sprintf('User not found: %s', $userCode));

            return Command::FAILURE;
        }

        return $this->generateForLead($io, $lead, $user, $input, $dryRun);
    }

    private function generateForLead(
        SymfonyStyle $io,
        Lead $lead,
        User $user,
        InputInterface $input,
        bool $dryRun
    ): int {
        // Find proposal if specified
        $proposal = null;
        if ($input->getOption('proposal')) {
            $proposal = $this->proposalRepository->find($input->getOption('proposal'));
            if (!$proposal) {
                $io->error(sprintf('Proposal not found: %s', $input->getOption('proposal')));

                return Command::FAILURE;
            }
        }

        // Determine recipient email
        $recipientEmail = $input->getOption('email') ?? $lead->getEmail();
        if (empty($recipientEmail)) {
            $io->error('No recipient email available. Use --email to specify one.');

            return Command::FAILURE;
        }

        $io->section('Offer Generation');
        $io->table([], [
            ['Lead', $lead->getDomain() ?? $lead->getId()?->toRfc4122()],
            ['User', $user->getCode()],
            ['Recipient', $recipientEmail],
            ['Proposal', $proposal ? $proposal->getId()?->toRfc4122() : 'none'],
            ['Industry', $lead->getIndustry()?->value ?? 'unknown'],
            ['Analysis Score', $lead->getLatestAnalysis()?->getTotalScore() ?? 'N/A'],
        ]);

        if ($dryRun) {
            $io->success('Dry run - no offer generated');

            return Command::SUCCESS;
        }

        try {
            $options = [
                'skip_ai' => $input->getOption('skip-ai'),
            ];

            if ($input->getOption('template')) {
                $options['template_name'] = $input->getOption('template');
            }

            $offer = $this->offerService->createAndGenerate(
                $lead,
                $user,
                $proposal,
                $recipientEmail,
                $options,
            );

            $io->success(sprintf(
                'Offer generated: %s (status: %s)',
                $offer->getId()?->toRfc4122(),
                $offer->getStatus()->value
            ));

            // Show preview
            $io->section('Email Preview');
            $io->text(sprintf('<info>Subject:</info> %s', $offer->getSubject()));
            $io->newLine();
            $io->text('<info>Body:</info>');
            $io->text($offer->getPlainTextBody() ?? strip_tags($offer->getBody() ?? ''));

            // Check if we should send
            if ($input->getOption('send')) {
                $io->note('--send flag requires approval first. Submitting for approval...');

                $this->offerService->submitForApproval($offer);
                $this->offerService->approve($offer, $user);

                // Check rate limits
                $rateLimitResult = $this->offerService->canSend($offer);

                if (!$rateLimitResult->allowed) {
                    $io->warning(sprintf('Cannot send: %s', $rateLimitResult->reason));

                    return Command::SUCCESS;
                }

                $this->offerService->send($offer);
                $io->success('Offer sent!');
            }

            // Show AI metadata
            $meta = $offer->getAiMetadata();
            if (!empty($meta['personalization_applied'])) {
                $io->note(sprintf(
                    'AI personalization applied (tokens: in=%d, out=%d)',
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
        $userCode = $input->getOption('user');

        if (!$userCode) {
            $io->error('--user is required for batch processing');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['code' => $userCode]);
        if (!$user) {
            $io->error(sprintf('User not found: %s', $userCode));

            return Command::FAILURE;
        }

        $limit = (int) $input->getOption('limit');

        $io->section('Batch Processing');

        // Find leads with analysis but no offers yet
        $leads = $this->leadRepository->createQueryBuilder('l')
            ->leftJoin('l.analyses', 'a')
            ->leftJoin(\App\Entity\Offer::class, 'o', 'WITH', 'o.lead = l AND o.user = :user')
            ->where('l.user = :user')
            ->andWhere('a.id IS NOT NULL')
            ->andWhere('o.id IS NULL')
            ->andWhere('l.email IS NOT NULL')
            ->setParameter('user', $user)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (empty($leads)) {
            $io->success('No leads to process');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d leads to process', count($leads)));

        if ($dryRun) {
            foreach ($leads as $lead) {
                $io->text(sprintf(
                    '  - %s (%s)',
                    $lead->getDomain() ?? $lead->getId()?->toRfc4122(),
                    $lead->getEmail()
                ));
            }
            $io->success('Dry run - no offers generated');

            return Command::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($leads as $lead) {
            $io->text(sprintf(
                'Processing: %s (%s)',
                $lead->getDomain() ?? $lead->getId()?->toRfc4122(),
                $lead->getEmail()
            ));

            try {
                $options = [
                    'skip_ai' => $input->getOption('skip-ai'),
                ];

                $this->offerService->createAndGenerate(
                    $lead,
                    $user,
                    null,
                    $lead->getEmail(),
                    $options,
                );
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
