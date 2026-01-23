<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Enum\AnalysisStatus;
use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;
use App\Repository\AnalysisResultRepository;
use App\Service\Analyzer\IssueRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AnalysisResultRepository::class)]
#[ORM\Table(name: 'analysis_results')]
#[ORM\Index(name: 'analysis_results_analysis_id_idx', columns: ['analysis_id'])]
#[ORM\Index(name: 'analysis_results_category_idx', columns: ['category'])]
#[ORM\Index(name: 'analysis_results_status_idx', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'analysis_results_analysis_category_unique', columns: ['analysis_id', 'category'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiResource(
    uriTemplate: '/analyses/{analysisId}/results',
    operations: [new GetCollection()],
    uriVariables: [
        'analysisId' => new Link(toProperty: 'analysis', fromClass: Analysis::class),
    ],
)]
class AnalysisResult
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Analysis::class, inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Analysis $analysis = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: IssueCategory::class)]
    private IssueCategory $category;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AnalysisStatus::class)]
    private AnalysisStatus $status = AnalysisStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var array<string, mixed> Raw data from the analyzer */
    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    /**
     * Issues array - new format stores only {code, evidence}, old format has full data.
     * Use IssueRegistry to get full issue metadata from code.
     *
     * @var array<int, array{
     *     code: string,
     *     evidence?: ?string,
     *     severity?: string,
     *     category?: string,
     *     title?: string,
     *     description?: string,
     *     impact?: ?string
     * }>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $issues = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $score = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): static
    {
        $this->analysis = $analysis;

        return $this;
    }

    public function getCategory(): IssueCategory
    {
        return $this->category;
    }

    public function setCategory(IssueCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getStatus(): AnalysisStatus
    {
        return $this->status;
    }

    public function setStatus(AnalysisStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /** @param array<string, mixed> $rawData */
    public function setRawData(array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    /**
     * Get raw issues array as stored in DB.
     * New format: {code, evidence}, old format has full data.
     *
     * @return array<int, array{
     *     code: string,
     *     evidence?: ?string,
     *     severity?: string,
     *     category?: string,
     *     title?: string,
     *     description?: string,
     *     impact?: ?string
     * }>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * @param array<int, array{code: string, evidence?: ?string}> $issues
     */
    public function setIssues(array $issues): static
    {
        $this->issues = $issues;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markAsRunning(): static
    {
        $this->status = AnalysisStatus::RUNNING;
        $this->startedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markAsCompleted(): static
    {
        $this->status = AnalysisStatus::COMPLETED;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markAsFailed(string $errorMessage): static
    {
        $this->status = AnalysisStatus::FAILED;
        $this->completedAt = new \DateTimeImmutable();
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getIssueCount(): int
    {
        return count($this->issues);
    }

    public function getCriticalIssueCount(): int
    {
        return count(array_filter($this->issues, function (array $issue): bool {
            // Support both old format (has severity) and new format (only code)
            if (isset($issue['severity'])) {
                return $issue['severity'] === 'critical';
            }

            // New format - get severity from registry
            return IssueRegistry::getSeverity($issue['code']) === IssueSeverity::CRITICAL;
        }));
    }

    public function hasCriticalIssues(): bool
    {
        return $this->getCriticalIssueCount() > 0;
    }

    /**
     * Get issues enriched with metadata from IssueRegistry.
     * Returns full issue data including title, description, severity, impact.
     *
     * @return array<int, array{
     *     code: string,
     *     evidence: ?string,
     *     severity: string,
     *     category: string,
     *     title: string,
     *     description: string,
     *     impact: ?string
     * }>
     */
    public function getEnrichedIssues(): array
    {
        $enriched = [];

        foreach ($this->issues as $issue) {
            $code = $issue['code'];
            $evidence = $issue['evidence'] ?? null;

            // If the issue already has full data (old format), use it
            if (isset($issue['title'], $issue['severity'])) {
                $enriched[] = [
                    'code' => $code,
                    'evidence' => $evidence,
                    'severity' => $issue['severity'],
                    'category' => $issue['category'] ?? $this->category->value,
                    'title' => $issue['title'],
                    'description' => $issue['description'] ?? '',
                    'impact' => $issue['impact'] ?? null,
                ];
                continue;
            }

            // New format - get metadata from IssueRegistry
            $def = IssueRegistry::get($code);

            if ($def !== null) {
                $enriched[] = [
                    'code' => $code,
                    'evidence' => $evidence,
                    'severity' => $def['severity']->value,
                    'category' => $def['category']->value,
                    'title' => $def['title'],
                    'description' => $def['description'],
                    'impact' => $def['impact'],
                ];
            } else {
                // Fallback for unknown codes
                $enriched[] = [
                    'code' => $code,
                    'evidence' => $evidence,
                    'severity' => IssueSeverity::OPTIMIZATION->value,
                    'category' => $this->category->value,
                    'title' => $code,
                    'description' => '',
                    'impact' => null,
                ];
            }
        }

        return $enriched;
    }
}
