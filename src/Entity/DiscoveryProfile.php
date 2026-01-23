<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\DiscoveryProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Discovery Profile - combines discovery settings, analyzer configuration, and industry.
 *
 * A named profile that determines:
 * - Which sources to use for lead discovery
 * - Which search queries to run
 * - Which analyzers to run and their configuration
 * - Whether to auto-analyze discovered leads
 */
#[ORM\Entity(repositoryClass: DiscoveryProfileRepository::class)]
#[ORM\Table(name: 'discovery_profiles')]
#[ORM\Index(name: 'discovery_profiles_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'discovery_profiles_is_default_idx', columns: ['is_default'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'isDefault' => 'exact',
])]
class DiscoveryProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'discoveryProfiles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    // Discovery Settings
    #[ORM\Column(options: ['default' => true])]
    private bool $discoveryEnabled = true;

    /** Single discovery source (google, firmy_cz, seznam, atlas_skolstvi, etc.) */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $discoverySource = null;

    /**
     * Source-specific settings.
     *
     * Format depends on source:
     * - google/seznam: {"queries": ["webdesign praha", "tvorba webu"]}
     * - firmy_cz/ekatalog: {"queries": ["restaurace", "autoservisy"]}
     * - atlas_skolstvi: {"schoolTypes": ["stredni-skoly", "vysoke-skoly"], "regions": ["praha"]}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $sourceSettings = [];

    /**
     * @var array<string> Search queries to run (legacy, now in sourceSettings.queries)
     * @deprecated Use sourceSettings['queries'] instead
     */
    #[ORM\Column(type: Types::JSON)]
    private array $discoveryQueries = [];

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 50])]
    #[Assert\Range(min: 1, max: 500)]
    private int $discoveryLimit = 50;

    #[ORM\Column(options: ['default' => true])]
    private bool $extractData = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $linkCompany = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 5])]
    #[Assert\Range(min: 1, max: 10)]
    private int $priority = 5;

    // Analysis Settings
    #[ORM\Column(options: ['default' => false])]
    private bool $autoAnalyze = false;

    /**
     * Analyzer configurations.
     *
     * Format: {
     *   "category_code": {
     *     "enabled": true,
     *     "priority": 5,
     *     "thresholds": {"min_score": 50},
     *     "ignoreCodes": ["SSL_EXPIRED"]
     *   }
     * }
     *
     * @var array<string, array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $analyzerConfigs = [];

    // Leads discovered using this profile
    /** @var Collection<int, Lead> */
    #[ORM\OneToMany(targetEntity: Lead::class, mappedBy: 'discoveryProfile', fetch: 'EXTRA_LAZY')]
    private Collection $leads;

    // Timestamps
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->leads = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isDiscoveryEnabled(): bool
    {
        return $this->discoveryEnabled;
    }

    public function setDiscoveryEnabled(bool $discoveryEnabled): static
    {
        $this->discoveryEnabled = $discoveryEnabled;

        return $this;
    }

    public function getDiscoverySource(): ?string
    {
        return $this->discoverySource;
    }

    public function setDiscoverySource(?string $discoverySource): static
    {
        $this->discoverySource = $discoverySource;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSourceSettings(): array
    {
        return $this->sourceSettings;
    }

    /**
     * @param array<string, mixed> $sourceSettings
     */
    public function setSourceSettings(array $sourceSettings): static
    {
        $this->sourceSettings = $sourceSettings;

        return $this;
    }

    /**
     * Get a specific source setting.
     */
    public function getSourceSetting(string $key, mixed $default = null): mixed
    {
        return $this->sourceSettings[$key] ?? $default;
    }

    /**
     * Set a specific source setting.
     */
    public function setSourceSetting(string $key, mixed $value): static
    {
        $this->sourceSettings[$key] = $value;

        return $this;
    }

    /**
     * Get queries from sourceSettings (for query-based sources).
     *
     * @return array<string>
     */
    public function getQueries(): array
    {
        // First check new location in sourceSettings
        $queries = $this->sourceSettings['queries'] ?? null;
        if (\is_array($queries) && !empty($queries)) {
            return $queries;
        }

        // Fallback to legacy discoveryQueries
        return $this->discoveryQueries;
    }

    /**
     * Set queries in sourceSettings.
     *
     * @param array<string> $queries
     */
    public function setQueries(array $queries): static
    {
        $this->sourceSettings['queries'] = $queries;

        return $this;
    }

    /**
     * @deprecated Use getDiscoverySource() instead
     * @return array<string>
     */
    public function getDiscoverySources(): array
    {
        // Return single source as array for backwards compatibility
        return $this->discoverySource !== null ? [$this->discoverySource] : [];
    }

    /**
     * @deprecated Use setDiscoverySource() instead
     * @param array<string> $discoverySources
     */
    public function setDiscoverySources(array $discoverySources): static
    {
        // Take first source for backwards compatibility
        $this->discoverySource = $discoverySources[0] ?? null;

        return $this;
    }

    /**
     * @deprecated Use getQueries() instead
     * @return array<string>
     */
    public function getDiscoveryQueries(): array
    {
        return $this->getQueries();
    }

    /**
     * @deprecated Use setQueries() instead
     * @param array<string> $discoveryQueries
     */
    public function setDiscoveryQueries(array $discoveryQueries): static
    {
        $this->discoveryQueries = $discoveryQueries;
        $this->sourceSettings['queries'] = $discoveryQueries;

        return $this;
    }

    /**
     * Get discovery queries as newline-separated text (for form display).
     */
    public function getDiscoveryQueriesText(): string
    {
        return implode("\n", $this->getQueries());
    }

    /**
     * Set discovery queries from newline-separated text (for form input).
     */
    public function setDiscoveryQueriesText(?string $text): static
    {
        if ($text === null || $text === '') {
            $queries = [];
        } else {
            $lines = explode("\n", $text);
            $queries = array_values(array_filter(array_map('trim', $lines)));
        }

        $this->discoveryQueries = $queries;
        $this->sourceSettings['queries'] = $queries;

        return $this;
    }

    // =========================================================================
    // Atlas Školství specific settings (virtual properties for form binding)
    // =========================================================================

    /**
     * Get school types for Atlas Školství source.
     *
     * @return array<string>
     */
    public function getSchoolTypes(): array
    {
        return $this->sourceSettings['schoolTypes'] ?? [];
    }

    /**
     * Set school types for Atlas Školství source.
     *
     * @param array<string>|null $schoolTypes
     */
    public function setSchoolTypes(?array $schoolTypes): static
    {
        $this->sourceSettings['schoolTypes'] = $schoolTypes ?? [];

        return $this;
    }

    /**
     * Get regions for Atlas Školství source.
     *
     * @return array<string>
     */
    public function getSchoolRegions(): array
    {
        return $this->sourceSettings['regions'] ?? [];
    }

    /**
     * Set regions for Atlas Školství source.
     *
     * @param array<string>|null $regions
     */
    public function setSchoolRegions(?array $regions): static
    {
        $this->sourceSettings['regions'] = $regions ?? [];

        return $this;
    }

    /**
     * Get school districts for JMK Katalog.
     *
     * @return array<string>
     */
    public function getSchoolDistricts(): array
    {
        return $this->sourceSettings['districts'] ?? [];
    }

    /**
     * Set school districts for JMK Katalog.
     *
     * @param array<string>|null $districts
     */
    public function setSchoolDistricts(?array $districts): static
    {
        $this->sourceSettings['districts'] = $districts ?? [];

        return $this;
    }

    public function getDiscoveryLimit(): int
    {
        return $this->discoveryLimit;
    }

    public function setDiscoveryLimit(int $discoveryLimit): static
    {
        $this->discoveryLimit = $discoveryLimit;

        return $this;
    }

    public function isExtractData(): bool
    {
        return $this->extractData;
    }

    public function setExtractData(bool $extractData): static
    {
        $this->extractData = $extractData;

        return $this;
    }

    public function isLinkCompany(): bool
    {
        return $this->linkCompany;
    }

    public function setLinkCompany(bool $linkCompany): static
    {
        $this->linkCompany = $linkCompany;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function isAutoAnalyze(): bool
    {
        return $this->autoAnalyze;
    }

    public function setAutoAnalyze(bool $autoAnalyze): static
    {
        $this->autoAnalyze = $autoAnalyze;

        return $this;
    }

    /** @return array<string, array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>}> */
    public function getAnalyzerConfigs(): array
    {
        return $this->analyzerConfigs;
    }

    /** @param array<string, array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>}> $analyzerConfigs */
    public function setAnalyzerConfigs(array $analyzerConfigs): static
    {
        $this->analyzerConfigs = $analyzerConfigs;

        return $this;
    }

    /**
     * Get analyzer configs as JSON string (for form display).
     */
    public function getAnalyzerConfigsJson(): string
    {
        if (empty($this->analyzerConfigs)) {
            return '{}';
        }

        return json_encode($this->analyzerConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Set analyzer configs from JSON string (for form input).
     */
    public function setAnalyzerConfigsJson(?string $json): static
    {
        if ($json === null || $json === '' || $json === '{}') {
            $this->analyzerConfigs = [];
        } else {
            $decoded = json_decode($json, true);
            $this->analyzerConfigs = is_array($decoded) ? $decoded : [];
        }

        return $this;
    }

    /**
     * Get config for a specific analyzer category.
     *
     * @return array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>}
     */
    public function getAnalyzerConfig(string $category): array
    {
        return $this->analyzerConfigs[$category] ?? [];
    }

    /**
     * Set config for a specific analyzer category.
     *
     * @param array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>} $config
     */
    public function setAnalyzerConfig(string $category, array $config): static
    {
        $this->analyzerConfigs[$category] = $config;

        return $this;
    }

    /**
     * Check if a specific analyzer category is enabled.
     */
    public function isAnalyzerEnabled(string $category): bool
    {
        $config = $this->analyzerConfigs[$category] ?? [];

        return $config['enabled'] ?? true;
    }

    /**
     * Get ignore codes for a specific analyzer category.
     *
     * @return array<string>
     */
    public function getIgnoreCodes(string $category): array
    {
        $config = $this->analyzerConfigs[$category] ?? [];

        return $config['ignoreCodes'] ?? [];
    }

    /** @return Collection<int, Lead> */
    public function getLeads(): Collection
    {
        return $this->leads;
    }

    public function addLead(Lead $lead): static
    {
        if (!$this->leads->contains($lead)) {
            $this->leads->add($lead);
            $lead->setDiscoveryProfile($this);
        }

        return $this;
    }

    public function removeLead(Lead $lead): static
    {
        if ($this->leads->removeElement($lead)) {
            if ($lead->getDiscoveryProfile() === $this) {
                $lead->setDiscoveryProfile(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name ?? (string) $this->id;
    }
}
