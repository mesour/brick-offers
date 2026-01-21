<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

interface LeadAnalyzerInterface
{
    /**
     * Check if this analyzer supports the given category.
     */
    public function supports(IssueCategory $category): bool;

    /**
     * Get the category this analyzer handles.
     */
    public function getCategory(): IssueCategory;

    /**
     * Analyze a lead and return the result.
     */
    public function analyze(Lead $lead): AnalyzerResult;

    /**
     * Get the priority of this analyzer (lower = runs first).
     */
    public function getPriority(): int;

    /**
     * Get the industries this analyzer supports.
     * Empty array means all industries (universal analyzer).
     *
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array;

    /**
     * Check if this analyzer is universal (runs for all industries).
     */
    public function isUniversal(): bool;

    /**
     * Check if this analyzer should run for a specific industry.
     */
    public function supportsIndustry(?Industry $industry): bool;
}
