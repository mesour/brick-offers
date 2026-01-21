<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Repository\CompetitorSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a snapshot of competitor data for change detection.
 * Used to track portfolio, pricing, services, and other changes over time.
 */
#[ORM\Entity(repositoryClass: CompetitorSnapshotRepository::class)]
#[ORM\Table(name: 'competitor_snapshots')]
#[ORM\Index(name: 'competitor_snapshots_domain_idx', columns: ['monitored_domain_id'])]
#[ORM\Index(name: 'competitor_snapshots_type_idx', columns: ['snapshot_type'])]
#[ORM\Index(name: 'competitor_snapshots_significance_idx', columns: ['significance'])]
#[ORM\Index(name: 'competitor_snapshots_created_at_idx', columns: ['created_at'])]
#[ORM\Index(name: 'competitor_snapshots_domain_type_idx', columns: ['monitored_domain_id', 'snapshot_type'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'monitoredDomain.id' => 'exact',
    'monitoredDomain.domain' => 'partial',
    'snapshotType' => 'exact',
    'significance' => 'exact',
    'hasChanges' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
class CompetitorSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class, inversedBy: 'snapshots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MonitoredDomain $monitoredDomain;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CompetitorSnapshotType::class)]
    private CompetitorSnapshotType $snapshotType;

    // Content hash for quick change detection
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousHash = null;

    // Whether changes were detected compared to previous snapshot
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasChanges = false;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: ChangeSignificance::class)]
    private ?ChangeSignificance $significance = null;

    // Raw data from the snapshot
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    // Detected changes (if any)
    /** @var array<array{field: string, before: mixed, after: mixed, significance: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $changes = [];

    // Metrics extracted from the snapshot
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metrics = [];

    // Source URL that was analyzed
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $sourceUrl = null;

    // Reference to previous snapshot for easy navigation
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $previousSnapshot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getMonitoredDomain(): MonitoredDomain
    {
        return $this->monitoredDomain;
    }

    public function setMonitoredDomain(MonitoredDomain $monitoredDomain): static
    {
        $this->monitoredDomain = $monitoredDomain;

        return $this;
    }

    public function getSnapshotType(): CompetitorSnapshotType
    {
        return $this->snapshotType;
    }

    public function setSnapshotType(CompetitorSnapshotType $snapshotType): static
    {
        $this->snapshotType = $snapshotType;

        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function setPreviousHash(?string $previousHash): static
    {
        $this->previousHash = $previousHash;

        return $this;
    }

    public function hasChanges(): bool
    {
        return $this->hasChanges;
    }

    public function setHasChanges(bool $hasChanges): static
    {
        $this->hasChanges = $hasChanges;

        return $this;
    }

    public function getSignificance(): ?ChangeSignificance
    {
        return $this->significance;
    }

    public function setSignificance(?ChangeSignificance $significance): static
    {
        $this->significance = $significance;

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

    /** @return array<array{field: string, before: mixed, after: mixed, significance: string}> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /** @param array<array{field: string, before: mixed, after: mixed, significance: string}> $changes */
    public function setChanges(array $changes): static
    {
        $this->changes = $changes;
        $this->hasChanges = !empty($changes);

        return $this;
    }

    /**
     * Add a single change to the list.
     */
    public function addChange(string $field, mixed $before, mixed $after, ChangeSignificance $significance): static
    {
        $this->changes[] = [
            'field' => $field,
            'before' => $before,
            'after' => $after,
            'significance' => $significance->value,
        ];
        $this->hasChanges = true;

        // Update overall significance to the highest found
        if ($this->significance === null || $significance->getWeight() > $this->significance->getWeight()) {
            $this->significance = $significance;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /** @param array<string, mixed> $metrics */
    public function setMetrics(array $metrics): static
    {
        $this->metrics = $metrics;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getPreviousSnapshot(): ?self
    {
        return $this->previousSnapshot;
    }

    public function setPreviousSnapshot(?self $previousSnapshot): static
    {
        $this->previousSnapshot = $previousSnapshot;
        if ($previousSnapshot !== null) {
            $this->previousHash = $previousSnapshot->getContentHash();
        }

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
     * Calculate content hash from raw data.
     */
    public static function calculateHash(array $data): string
    {
        // Sort keys recursively for consistent hashing
        $normalized = self::normalizeForHash($data);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize array for consistent hashing.
     */
    private static function normalizeForHash(mixed $data): mixed
    {
        if (is_array($data)) {
            ksort($data);

            return array_map([self::class, 'normalizeForHash'], $data);
        }

        return $data;
    }

    /**
     * Check if changes are significant enough for alerting.
     */
    public function shouldAlert(): bool
    {
        return $this->hasChanges
            && $this->significance !== null
            && $this->significance->shouldAlert();
    }

    /**
     * Get changes filtered by significance.
     *
     * @return array<array{field: string, before: mixed, after: mixed, significance: string}>
     */
    public function getChangesBySignificance(ChangeSignificance $minSignificance): array
    {
        $minWeight = $minSignificance->getWeight();

        return array_filter($this->changes, function (array $change) use ($minWeight) {
            $significance = ChangeSignificance::tryFrom($change['significance']);

            return $significance !== null && $significance->getWeight() >= $minWeight;
        });
    }
}
