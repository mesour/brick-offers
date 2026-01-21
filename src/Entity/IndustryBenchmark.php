<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\Industry;
use App\Repository\IndustryBenchmarkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Industry benchmark data for comparing leads against their industry peers.
 * Aggregates analysis data across all leads in an industry for a given period.
 */
#[ORM\Entity(repositoryClass: IndustryBenchmarkRepository::class)]
#[ORM\Table(name: 'industry_benchmarks')]
#[ORM\UniqueConstraint(name: 'benchmarks_industry_period_unique', columns: ['industry', 'period_start'])]
#[ORM\Index(name: 'benchmarks_industry_idx', columns: ['industry', 'period_start'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['periodStart' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'industry' => 'exact',
])]
class IndustryBenchmark
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $avgScore = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $medianScore = 0.0;

    /** @var array{p10?: float, p25?: float, p50?: float, p75?: float, p90?: float} */
    #[ORM\Column(type: Types::JSON)]
    private array $percentiles = [];

    /** @var array<string, float> Average scores per category */
    #[ORM\Column(type: Types::JSON)]
    private array $avgCategoryScores = [];

    /** @var array<array{code: string, count: int, percentage: float}> Most common issues */
    #[ORM\Column(type: Types::JSON)]
    private array $topIssues = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $sampleSize = 0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $avgIssueCount = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $avgCriticalIssueCount = 0.0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getIndustry(): ?Industry
    {
        return $this->industry;
    }

    public function setIndustry(Industry $industry): static
    {
        $this->industry = $industry;

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

    public function getAvgScore(): float
    {
        return $this->avgScore;
    }

    public function setAvgScore(float $avgScore): static
    {
        $this->avgScore = $avgScore;

        return $this;
    }

    public function getMedianScore(): float
    {
        return $this->medianScore;
    }

    public function setMedianScore(float $medianScore): static
    {
        $this->medianScore = $medianScore;

        return $this;
    }

    /** @return array{p10?: float, p25?: float, p50?: float, p75?: float, p90?: float} */
    public function getPercentiles(): array
    {
        return $this->percentiles;
    }

    /** @param array{p10?: float, p25?: float, p50?: float, p75?: float, p90?: float} $percentiles */
    public function setPercentiles(array $percentiles): static
    {
        $this->percentiles = $percentiles;

        return $this;
    }

    /** @return array<string, float> */
    public function getAvgCategoryScores(): array
    {
        return $this->avgCategoryScores;
    }

    /** @param array<string, float> $avgCategoryScores */
    public function setAvgCategoryScores(array $avgCategoryScores): static
    {
        $this->avgCategoryScores = $avgCategoryScores;

        return $this;
    }

    /** @return array<array{code: string, count: int, percentage: float}> */
    public function getTopIssues(): array
    {
        return $this->topIssues;
    }

    /** @param array<array{code: string, count: int, percentage: float}> $topIssues */
    public function setTopIssues(array $topIssues): static
    {
        $this->topIssues = $topIssues;

        return $this;
    }

    public function getSampleSize(): int
    {
        return $this->sampleSize;
    }

    public function setSampleSize(int $sampleSize): static
    {
        $this->sampleSize = $sampleSize;

        return $this;
    }

    public function getAvgIssueCount(): float
    {
        return $this->avgIssueCount;
    }

    public function setAvgIssueCount(float $avgIssueCount): static
    {
        $this->avgIssueCount = $avgIssueCount;

        return $this;
    }

    public function getAvgCriticalIssueCount(): float
    {
        return $this->avgCriticalIssueCount;
    }

    public function setAvgCriticalIssueCount(float $avgCriticalIssueCount): static
    {
        $this->avgCriticalIssueCount = $avgCriticalIssueCount;

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
     * Get the percentile ranking for a given score.
     */
    public function getPercentileRanking(int $score): string
    {
        if (empty($this->percentiles)) {
            return 'unknown';
        }

        if ($score >= ($this->percentiles['p90'] ?? PHP_INT_MAX)) {
            return 'top10';
        }
        if ($score >= ($this->percentiles['p75'] ?? PHP_INT_MAX)) {
            return 'top25';
        }
        if ($score >= ($this->percentiles['p50'] ?? PHP_INT_MAX)) {
            return 'above_average';
        }
        if ($score >= ($this->percentiles['p25'] ?? PHP_INT_MIN)) {
            return 'below_average';
        }

        return 'bottom25';
    }

    /**
     * Calculate percentile for a given score (0-100).
     */
    public function calculatePercentile(int $score): ?float
    {
        if (empty($this->percentiles)) {
            return null;
        }

        $p10 = $this->percentiles['p10'] ?? 0;
        $p25 = $this->percentiles['p25'] ?? 0;
        $p50 = $this->percentiles['p50'] ?? 0;
        $p75 = $this->percentiles['p75'] ?? 0;
        $p90 = $this->percentiles['p90'] ?? 0;

        if ($score >= $p90) {
            return 90 + (($score - $p90) / max(1, 100 - $p90)) * 10;
        }
        if ($score >= $p75) {
            return 75 + (($score - $p75) / max(1, $p90 - $p75)) * 15;
        }
        if ($score >= $p50) {
            return 50 + (($score - $p50) / max(1, $p75 - $p50)) * 25;
        }
        if ($score >= $p25) {
            return 25 + (($score - $p25) / max(1, $p50 - $p25)) * 25;
        }
        if ($score >= $p10) {
            return 10 + (($score - $p10) / max(1, $p25 - $p10)) * 15;
        }

        return ($score / max(1, $p10)) * 10;
    }
}
