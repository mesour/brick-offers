<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Enum\IssueCategory;

final readonly class AnalyzerResult
{
    /**
     * @param array<Issue> $issues
     * @param array<string, mixed> $rawData
     */
    public function __construct(
        public IssueCategory $category,
        public array $issues,
        public array $rawData,
        public bool $success,
        public ?string $errorMessage = null,
    ) {}

    public static function success(IssueCategory $category, array $issues, array $rawData): self
    {
        return new self(
            category: $category,
            issues: $issues,
            rawData: $rawData,
            success: true,
        );
    }

    public static function failure(IssueCategory $category, string $errorMessage): self
    {
        return new self(
            category: $category,
            issues: [],
            rawData: [],
            success: false,
            errorMessage: $errorMessage,
        );
    }

    public function getIssueCount(): int
    {
        return count($this->issues);
    }

    public function getScore(): int
    {
        $score = 0;
        foreach ($this->issues as $issue) {
            $score += $issue->getWeight();
        }

        return $score;
    }
}
