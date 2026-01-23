<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\Industry;
use App\Enum\SnapshotPeriod;
use App\Repository\AnalysisSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregated snapshot of analysis data for trending and benchmarking.
 * Stores periodic summaries (daily/weekly/monthly) of analysis results.
 */
#[ORM\Entity(repositoryClass: AnalysisSnapshotRepository::class)]
#[ORM\Table(name: 'analysis_snapshots')]
#[ORM\UniqueConstraint(name: 'snapshots_lead_period_unique', columns: ['lead_id', 'period_type', 'period_start'])]
#[ORM\Index(name: 'snapshots_lead_period_idx', columns: ['lead_id', 'period_start'])]
#[ORM\Index(name: 'snapshots_industry_period_idx', columns: ['industry', 'period_start'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['periodStart' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'lead.id' => 'exact',
    'industry' => 'exact',
    'periodType' => 'exact',
])]
class AnalysisSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Lead::class, inversedBy: 'snapshots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lead $lead = null;

    #[ORM\ManyToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Analysis $analysis = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: SnapshotPeriod::class)]
    private SnapshotPeriod $periodType = SnapshotPeriod::WEEK;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalScore = 0;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $categoryScores = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $issueCount = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $criticalIssueCount = 0;

    /** @var array<string> Top 5 issues by severity */
    #[ORM\Column(type: Types::JSON)]
    private array $topIssues = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $scoreDelta = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): static
    {
        $this->analysis = $analysis;

        return $this;
    }

    public function getPeriodType(): SnapshotPeriod
    {
        return $this->periodType;
    }

    public function setPeriodType(SnapshotPeriod $periodType): static
    {
        $this->periodType = $periodType;

        return $this;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
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

    /** @return array<string, int> */
    public function getCategoryScores(): array
    {
        return $this->categoryScores;
    }

    /** @param array<string, int> $categoryScores */
    public function setCategoryScores(array $categoryScores): static
    {
        $this->categoryScores = $categoryScores;

        return $this;
    }

    public function getIssueCount(): int
    {
        return $this->issueCount;
    }

    public function setIssueCount(int $issueCount): static
    {
        $this->issueCount = $issueCount;

        return $this;
    }

    public function getCriticalIssueCount(): int
    {
        return $this->criticalIssueCount;
    }

    public function setCriticalIssueCount(int $criticalIssueCount): static
    {
        $this->criticalIssueCount = $criticalIssueCount;

        return $this;
    }

    /** @return array<string> */
    public function getTopIssues(): array
    {
        return $this->topIssues;
    }

    /** @param array<string> $topIssues */
    public function setTopIssues(array $topIssues): static
    {
        $this->topIssues = $topIssues;

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

    public function getIndustry(): ?Industry
    {
        return $this->industry;
    }

    public function setIndustry(?Industry $industry): static
    {
        $this->industry = $industry;

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

    /**
     * Create a snapshot from an Analysis.
     */
    public static function fromAnalysis(Analysis $analysis, SnapshotPeriod $periodType): self
    {
        $snapshot = new self();
        $snapshot->setLead($analysis->getLead());
        $snapshot->setAnalysis($analysis);
        $snapshot->setPeriodType($periodType);
        $snapshot->setPeriodStart(self::calculatePeriodStart($periodType));
        $snapshot->setTotalScore($analysis->getTotalScore());
        $snapshot->setCategoryScores($analysis->getScores());
        $snapshot->setIssueCount($analysis->getIssueCount());
        $snapshot->setCriticalIssueCount($analysis->getCriticalIssueCount());
        $snapshot->setScoreDelta($analysis->getScoreDelta());
        $snapshot->setIndustry($analysis->getIndustry());

        // Get top 5 issue codes
        $issueCodes = $analysis->getIssueCodes();
        $snapshot->setTopIssues(array_slice($issueCodes, 0, 5));

        return $snapshot;
    }

    /**
     * Calculate the period start date based on period type.
     */
    private static function calculatePeriodStart(SnapshotPeriod $periodType): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($periodType) {
            SnapshotPeriod::DAY => $now->setTime(0, 0, 0),
            SnapshotPeriod::WEEK => $now->modify('monday this week')->setTime(0, 0, 0),
            SnapshotPeriod::MONTH => $now->modify('first day of this month')->setTime(0, 0, 0),
        };
    }
}
