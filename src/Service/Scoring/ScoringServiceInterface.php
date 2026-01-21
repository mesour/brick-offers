<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Analysis;
use App\Enum\LeadStatus;
use App\Service\Analyzer\Issue;

interface ScoringServiceInterface
{
    /**
     * Calculate scores from a list of issues.
     *
     * @param array<Issue> $issues
     * @return array{categoryScores: array<string, int>, totalScore: int}
     */
    public function calculateScores(array $issues): array;

    /**
     * Determine the appropriate lead status based on analysis results.
     */
    public function determineLeadStatus(Analysis $analysis): LeadStatus;
}
