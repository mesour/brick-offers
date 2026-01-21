<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Analysis;
use App\Enum\IssueCategory;
use App\Enum\LeadStatus;
use App\Service\Analyzer\Issue;

class WeightedScoringService implements ScoringServiceInterface
{
    // Score thresholds for quality states
    private const VERY_BAD_THRESHOLD = -50;
    private const BAD_THRESHOLD = -20;
    private const MIDDLE_THRESHOLD = -5;
    private const GOOD_THRESHOLD = 0;

    public function calculateScores(array $issues): array
    {
        $categoryScores = [];

        // Initialize all categories with 0
        foreach (IssueCategory::cases() as $category) {
            $categoryScores[$category->value] = 0;
        }

        // Calculate scores per category
        foreach ($issues as $issue) {
            $category = $issue->category->value;
            $categoryScores[$category] += $issue->getWeight();
        }

        // Calculate total score
        $totalScore = array_sum($categoryScores);

        return [
            'categoryScores' => $categoryScores,
            'totalScore' => $totalScore,
        ];
    }

    public function determineLeadStatus(Analysis $analysis): LeadStatus
    {
        $totalScore = $analysis->getTotalScore();
        $hasCritical = $analysis->hasCriticalIssues();

        // Any critical issue = at least BAD
        if ($hasCritical) {
            if ($totalScore < self::VERY_BAD_THRESHOLD) {
                return LeadStatus::VERY_BAD;
            }

            return LeadStatus::BAD;
        }

        // Determine by score thresholds
        if ($totalScore < self::VERY_BAD_THRESHOLD) {
            return LeadStatus::VERY_BAD;
        }

        if ($totalScore < self::BAD_THRESHOLD) {
            return LeadStatus::BAD;
        }

        if ($totalScore < self::MIDDLE_THRESHOLD) {
            return LeadStatus::MIDDLE;
        }

        if ($totalScore < self::GOOD_THRESHOLD) {
            return LeadStatus::QUALITY_GOOD;
        }

        // Score >= 0 and no critical issues
        return LeadStatus::SUPER;
    }
}
