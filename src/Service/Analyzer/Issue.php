<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;

final readonly class Issue
{
    public function __construct(
        public IssueCategory $category,
        public IssueSeverity $severity,
        public string $code,
        public string $title,
        public string $description,
        public ?string $evidence = null,
        public ?string $impact = null,
    ) {}

    /**
     * Convert to storage format (only code and evidence).
     * Metadata is retrieved from IssueRegistry when needed.
     *
     * @return array{code: string, evidence: ?string}
     */
    public function toStorageArray(): array
    {
        return [
            'code' => $this->code,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * Convert to full format for API/display.
     *
     * @return array{category: string, severity: string, code: string, title: string, description: string, evidence: ?string, impact: ?string}
     */
    public function toFullArray(): array
    {
        return [
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'evidence' => $this->evidence,
            'impact' => $this->impact,
        ];
    }

    /**
     * @deprecated Use toFullArray() instead
     * @return array{category: string, severity: string, code: string, title: string, description: string, evidence: ?string, impact: ?string}
     */
    public function toArray(): array
    {
        return $this->toFullArray();
    }

    /**
     * Create Issue from storage format.
     * Retrieves metadata from IssueRegistry.
     *
     * @param array{code: string, evidence?: ?string} $data
     */
    public static function fromStorageArray(array $data): self
    {
        $code = $data['code'];
        $evidence = $data['evidence'] ?? null;

        $def = IssueRegistry::get($code);

        if ($def === null) {
            // Fallback for unknown codes (e.g., dynamic accessibility codes)
            return new self(
                category: IssueCategory::HTTP,
                severity: IssueSeverity::OPTIMIZATION,
                code: $code,
                title: $code,
                description: '',
                evidence: $evidence,
                impact: null,
            );
        }

        return new self(
            category: $def['category'],
            severity: $def['severity'],
            code: $code,
            title: $def['title'],
            description: $def['description'],
            evidence: $evidence,
            impact: $def['impact'],
        );
    }

    public function getWeight(): int
    {
        return $this->severity->getWeight();
    }
}
