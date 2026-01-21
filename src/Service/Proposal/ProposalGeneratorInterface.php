<?php

declare(strict_types=1);

namespace App\Service\Proposal;

use App\Entity\Analysis;
use App\Enum\Industry;
use App\Enum\ProposalType;

/**
 * Interface for proposal generators.
 *
 * Each generator handles a specific industry/proposal type combination.
 * Generators are tagged with 'app.proposal_generator' for auto-discovery.
 */
interface ProposalGeneratorInterface
{
    /**
     * Check if this generator supports the given industry.
     */
    public function supports(Industry $industry): bool;

    /**
     * Get the type of proposal this generator creates.
     */
    public function getProposalType(): ProposalType;

    /**
     * Generate a proposal from an analysis.
     *
     * @param array<string, mixed> $options Additional generation options
     */
    public function generate(Analysis $analysis, array $options = []): ProposalResult;

    /**
     * Estimate the cost of generating a proposal.
     */
    public function estimateCost(Analysis $analysis): CostEstimate;

    /**
     * Get the generator name for identification.
     */
    public function getName(): string;
}
