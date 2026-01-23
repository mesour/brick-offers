<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Repository\AnalysisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'analyses')]
#[ORM\Index(name: 'analyses_lead_id_idx', columns: ['lead_id'])]
#[ORM\Index(name: 'analyses_status_idx', columns: ['status'])]
#[ORM\Index(name: 'analyses_total_score_idx', columns: ['total_score'])]
#[ORM\Index(name: 'analyses_industry_idx', columns: ['industry'])]
#[ORM\Index(name: 'analyses_sequence_idx', columns: ['lead_id', 'sequence_number'])]
#[ORM\Index(name: 'analyses_is_improved_idx', columns: ['is_improved'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'lead.id' => 'exact',
    'status' => 'exact',
    'industry' => 'exact',
])]
class Analysis
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Lead::class, inversedBy: 'analyses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lead $lead = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AnalysisStatus::class)]
    private AnalysisStatus $status = AnalysisStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, AnalysisResult> */
    #[ORM\OneToMany(targetEntity: AnalysisResult::class, mappedBy: 'analysis', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalScore = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isEshop = false;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $sequenceNumber = 1;

    #[ORM\ManyToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(name: 'previous_analysis_id', nullable: true, onDelete: 'SET NULL')]
    private ?Analysis $previousAnalysis = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $scoreDelta = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isImproved = false;

    /** @var array{added: array<string>, removed: array<string>, unchanged_count: int} */
    #[ORM\Column(type: Types::JSON)]
    private array $issueDelta = ['added' => [], 'removed' => [], 'unchanged_count' => 0];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): static
    {
        $this->lead = $lead;

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

    /** @return Collection<int, AnalysisResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(AnalysisResult $result): static
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setAnalysis($this);
        }

        return $this;
    }

    public function removeResult(AnalysisResult $result): static
    {
        if ($this->results->removeElement($result)) {
            if ($result->getAnalysis() === $this) {
                $result->setAnalysis(null);
            }
        }

        return $this;
    }

    public function getResultByCategory(IssueCategory $category): ?AnalysisResult
    {
        foreach ($this->results as $result) {
            if ($result->getCategory() === $category) {
                return $result;
            }
        }

        return null;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function setTotalScore(int $totalScore): static
    {
        $this->totalScore = $totalScore;

        return $this;
    }

    public function isEshop(): bool
    {
        return $this->isEshop;
    }

    public function setIsEshop(bool $isEshop): static
    {
        $this->isEshop = $isEshop;

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

    public function markAsFailed(): static
    {
        $this->status = AnalysisStatus::FAILED;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Calculate total score from all results.
     */
    public function calculateTotalScore(): int
    {
        $total = 0;
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                $total += $result->getScore();
            }
        }
        $this->totalScore = $total;

        return $total;
    }

    /**
     * Get scores per category.
     *
     * @return array<string, int>
     */
    public function getScores(): array
    {
        $scores = [];
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                $scores[$result->getCategory()->value] = $result->getScore();
            }
        }

        return $scores;
    }

    /**
     * Get all issues from all results.
     * New format: {code, evidence}, old format has full data.
     * Use Issue::fromStorageArray() to get full Issue objects.
     *
     * @return array<int, array{code: string, evidence?: ?string}>
     */
    public function getIssues(): array
    {
        $issues = [];
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                foreach ($result->getIssues() as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    public function getIssueCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                $count += $result->getIssueCount();
            }
        }

        return $count;
    }

    public function getCriticalIssueCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                $count += $result->getCriticalIssueCount();
            }
        }

        return $count;
    }

    public function hasCriticalIssues(): bool
    {
        return $this->getCriticalIssueCount() > 0;
    }

    public function getCompletedResultsCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                $count++;
            }
        }

        return $count;
    }

    public function getFailedResultsCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::FAILED) {
                $count++;
            }
        }

        return $count;
    }

    public function areAllResultsComplete(): bool
    {
        if ($this->results->isEmpty()) {
            return false;
        }

        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::PENDING || $result->getStatus() === AnalysisStatus::RUNNING) {
                return false;
            }
        }

        return true;
    }

    public function getIndustry(): ?Industry
    {
        return $this->industry;
    }

    public function setIndustry(?Industry $industry): static
    {
        $this->industry = $industry;

        return $this;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function setSequenceNumber(int $sequenceNumber): static
    {
        $this->sequenceNumber = $sequenceNumber;

        return $this;
    }

    public function getPreviousAnalysis(): ?Analysis
    {
        return $this->previousAnalysis;
    }

    public function setPreviousAnalysis(?Analysis $previousAnalysis): static
    {
        $this->previousAnalysis = $previousAnalysis;

        return $this;
    }

    public function getScoreDelta(): ?int
    {
        return $this->scoreDelta;
    }

    public function setScoreDelta(?int $scoreDelta): static
    {
        $this->scoreDelta = $scoreDelta;

        return $this;
    }

    public function isImproved(): bool
    {
        return $this->isImproved;
    }

    public function setIsImproved(bool $isImproved): static
    {
        $this->isImproved = $isImproved;

        return $this;
    }

    /**
     * @return array{added: array<string>, removed: array<string>, unchanged_count: int}
     */
    public function getIssueDelta(): array
    {
        return $this->issueDelta;
    }

    /**
     * @param array{added: array<string>, removed: array<string>, unchanged_count: int} $issueDelta
     */
    public function setIssueDelta(array $issueDelta): static
    {
        $this->issueDelta = $issueDelta;

        return $this;
    }

    /**
     * Calculate delta compared to previous analysis.
     * Call this after all results are completed.
     */
    public function calculateDelta(): void
    {
        if ($this->previousAnalysis === null) {
            return;
        }

        // Score delta
        $previousScore = $this->previousAnalysis->getTotalScore();
        $this->scoreDelta = $this->totalScore - $previousScore;
        $this->isImproved = $this->scoreDelta > 0;

        // Issue delta - compare issue codes
        $currentIssueCodes = $this->getIssueCodes();
        $previousIssueCodes = $this->previousAnalysis->getIssueCodes();

        $added = array_values(array_diff($currentIssueCodes, $previousIssueCodes));
        $removed = array_values(array_diff($previousIssueCodes, $currentIssueCodes));
        $unchangedCount = count(array_intersect($currentIssueCodes, $previousIssueCodes));

        $this->issueDelta = [
            'added' => $added,
            'removed' => $removed,
            'unchanged_count' => $unchangedCount,
        ];
    }

    /**
     * Get all unique issue codes from this analysis.
     *
     * @return array<string>
     */
    public function getIssueCodes(): array
    {
        $codes = [];
        foreach ($this->results as $result) {
            if ($result->getStatus() === AnalysisStatus::COMPLETED) {
                foreach ($result->getIssues() as $issue) {
                    if (isset($issue['code'])) {
                        $codes[] = $issue['code'];
                    }
                }
            }
        }

        return array_unique($codes);
    }

    /**
     * Check if this analysis has new critical issues compared to previous.
     */
    public function hasNewCriticalIssues(): bool
    {
        if (empty($this->issueDelta['added'])) {
            return false;
        }

        // Check if any added issue is critical
        // This would need IssueRegistry to check severity
        // For now, return true if there are any new issues
        return count($this->issueDelta['added']) > 0;
    }

    public function __toString(): string
    {
        $label = $this->lead?->getDomain() ?? 'Analysis';

        return sprintf('%s #%d', $label, $this->sequenceNumber);
    }
}
