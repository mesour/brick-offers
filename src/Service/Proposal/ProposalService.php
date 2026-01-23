<?php

declare(strict_types=1);

namespace App\Service\Proposal;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use App\Repository\ProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Service for managing proposals.
 */
class ProposalService
{
    /**
     * @var ProposalGeneratorInterface[]
     */
    private array $generators = [];

    /**
     * @param iterable<ProposalGeneratorInterface> $generators
     */
    public function __construct(
        #[TaggedIterator('app.proposal_generator')]
        iterable $generators,
        private readonly ProposalRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($generators as $generator) {
            $this->generators[] = $generator;
        }
    }

    /**
     * Find a generator that supports the given industry.
     */
    public function getGenerator(Industry $industry): ?ProposalGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->supports($industry)) {
                return $generator;
            }
        }

        return null;
    }

    /**
     * Get all available generators.
     *
     * @return ProposalGeneratorInterface[]
     */
    public function getGenerators(): array
    {
        return $this->generators;
    }

    /**
     * Create a new proposal for a lead.
     */
    public function createProposal(
        Lead $lead,
        User $user,
        ?ProposalType $type = null,
        ?Analysis $analysis = null,
    ): Proposal {
        // Validate: discovered leads must have an email
        if ($lead->getSource()->isDiscovered() && empty($lead->getEmail())) {
            throw new \LogicException(
                'Cannot create proposal for discovered lead without email address'
            );
        }

        $industry = $lead->getIndustry() ?? Industry::OTHER;
        $analysis ??= $lead->getLatestAnalysis();

        // Determine proposal type from generator or default
        if ($type === null) {
            $generator = $this->getGenerator($industry);
            $type = $generator?->getProposalType() ?? ProposalType::GENERIC_REPORT;
        }

        // Check for existing proposal of this type
        $existing = $this->repository->findByLeadAndType($lead, $type);
        if ($existing !== null) {
            throw new \LogicException(sprintf(
                'Proposal of type %s already exists for this lead',
                $type->value
            ));
        }

        $proposal = new Proposal();
        $proposal->setUser($user);
        $proposal->setLead($lead);
        $proposal->setAnalysis($analysis);
        $proposal->setType($type);
        $proposal->setIndustry($industry);
        $proposal->setStatus(ProposalStatus::GENERATING);
        $proposal->setTitle(sprintf('%s for %s', $type->label(), $lead->getDomain() ?? 'unknown'));

        $this->em->persist($proposal);
        $this->em->flush();

        $this->logger->info('Created proposal', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
            'lead_id' => $lead->getId()?->toRfc4122(),
            'type' => $type->value,
        ]);

        return $proposal;
    }

    /**
     * Generate content for a proposal.
     */
    public function generate(Proposal $proposal, array $options = []): void
    {
        $industry = $proposal->getIndustry() ?? Industry::OTHER;
        $generator = $this->getGenerator($industry);

        if ($generator === null) {
            $this->logger->error('No generator found for industry', [
                'industry' => $industry->value,
            ]);
            $proposal->setStatus(ProposalStatus::DRAFT);
            $proposal->setContent('No generator available for this industry.');
            $this->em->flush();

            return;
        }

        $analysis = $proposal->getAnalysis();
        if ($analysis === null) {
            $this->logger->error('No analysis available for proposal generation');
            $proposal->setStatus(ProposalStatus::DRAFT);
            $proposal->setContent('No analysis available.');
            $this->em->flush();

            return;
        }

        $this->logger->info('Generating proposal content', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
            'generator' => $generator->getName(),
        ]);

        $result = $generator->generate($analysis, $options);

        if ($result->success) {
            $proposal->setTitle($result->title);
            $proposal->setContent($result->content);
            $proposal->setSummary($result->summary);
            $proposal->setOutputs($result->outputs);
            $proposal->setAiMetadata($result->aiMetadata);
            $proposal->setStatus(ProposalStatus::DRAFT);
        } else {
            $proposal->setAiMetadata(['error' => $result->error]);
            // Keep in GENERATING status for retry, or set to DRAFT with error
            $proposal->setStatus(ProposalStatus::DRAFT);
            $proposal->setContent(sprintf('Generation failed: %s', $result->error));
        }

        $this->em->flush();

        $this->logger->info('Proposal generation completed', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
            'success' => $result->success,
        ]);
    }

    /**
     * Create and generate a proposal in one step.
     */
    public function createAndGenerate(
        Lead $lead,
        User $user,
        ?ProposalType $type = null,
        array $options = [],
    ): Proposal {
        $proposal = $this->createProposal($lead, $user, $type);
        $this->generate($proposal, $options);

        return $proposal;
    }

    /**
     * Find a recyclable proposal and assign it to a new user/lead.
     */
    public function findAndRecycle(
        User $newUser,
        Lead $newLead,
        ?Industry $industry = null,
        ?ProposalType $type = null,
    ): ?Proposal {
        $industry ??= $newLead->getIndustry() ?? Industry::OTHER;
        $type ??= $this->getGenerator($industry)?->getProposalType() ?? ProposalType::GENERIC_REPORT;

        $proposal = $this->repository->findRecyclable($industry, $type);

        if ($proposal === null) {
            return null;
        }

        return $this->recycle($proposal, $newUser, $newLead);
    }

    /**
     * Recycle a proposal to a new user and lead.
     */
    public function recycle(Proposal $proposal, User $newUser, ?Lead $newLead = null): Proposal
    {
        if (!$proposal->canBeRecycled()) {
            throw new \LogicException('This proposal cannot be recycled');
        }

        $this->logger->info('Recycling proposal', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
            'from_user' => $proposal->getUser()->getCode(),
            'to_user' => $newUser->getCode(),
        ]);

        $proposal->recycleTo($newUser, $newLead);
        $this->em->flush();

        return $proposal;
    }

    /**
     * Check if recycling is possible for the given criteria.
     */
    public function canRecycle(Industry $industry, ProposalType $type): bool
    {
        return $this->repository->findRecyclable($industry, $type) !== null;
    }

    /**
     * Approve a proposal.
     */
    public function approve(Proposal $proposal): void
    {
        $proposal->approve();
        $this->em->flush();

        $this->logger->info('Proposal approved', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
        ]);
    }

    /**
     * Reject a proposal.
     */
    public function reject(Proposal $proposal): void
    {
        $proposal->reject();
        $this->em->flush();

        $this->logger->info('Proposal rejected', [
            'proposal_id' => $proposal->getId()?->toRfc4122(),
            'recyclable' => $proposal->canBeRecycled(),
        ]);
    }

    /**
     * Estimate cost for generating a proposal.
     */
    public function estimateCost(Analysis $analysis, ?Industry $industry = null): ?CostEstimate
    {
        $industry ??= $analysis->getIndustry() ?? $analysis->getLead()?->getIndustry() ?? Industry::OTHER;
        $generator = $this->getGenerator($industry);

        return $generator?->estimateCost($analysis);
    }

    /**
     * Mark expired proposals.
     */
    public function markExpired(): int
    {
        $expired = $this->repository->findExpired();
        $count = 0;

        foreach ($expired as $proposal) {
            $proposal->setStatus(ProposalStatus::EXPIRED);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $this->logger->info('Marked proposals as expired', ['count' => $count]);
        }

        return $count;
    }
}
