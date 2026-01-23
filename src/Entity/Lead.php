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
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Enum\SnapshotPeriod;
use App\Repository\LeadRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'leads')]
#[ORM\UniqueConstraint(name: 'leads_user_domain_unique', columns: ['user_id', 'domain'])]
#[ORM\Index(name: 'leads_status_idx', columns: ['status'])]
#[ORM\Index(name: 'leads_source_idx', columns: ['source'])]
#[ORM\Index(name: 'leads_industry_idx', columns: ['industry'])]
#[ORM\Index(name: 'leads_last_analyzed_at_idx', columns: ['last_analyzed_at'])]
#[ORM\Index(name: 'leads_company_idx', columns: ['company_id'])]
#[ORM\Index(name: 'leads_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'domain' => 'partial',
    'source' => 'exact',
    'status' => 'exact',
    'industry' => 'exact',
    'type' => 'exact',
    'hasWebsite' => 'exact',
    'email' => 'partial',
    'companyName' => 'partial',
    'ico' => 'exact',
])]
class Lead
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Assert\Length(max: 500)]
    private ?string $url = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $domain = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: LeadSource::class)]
    private LeadSource $source = LeadSource::MANUAL;

    #[ORM\ManyToOne(targetEntity: Affiliate::class, inversedBy: 'leads')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Affiliate $affiliate = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'leads')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: LeadStatus::class)]
    private LeadStatus $status = LeadStatus::NEW;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 10)]
    private int $priority = 5;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dealAt = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\OneToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(name: 'latest_analysis_id', nullable: true, onDelete: 'SET NULL')]
    private ?Analysis $latestAnalysis = null;

    /** @var Collection<int, Analysis> */
    #[ORM\OneToMany(targetEntity: Analysis::class, mappedBy: 'lead', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $analyses;

    /** @var Collection<int, AnalysisSnapshot> */
    #[ORM\OneToMany(targetEntity: AnalysisSnapshot::class, mappedBy: 'lead', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['periodStart' => 'DESC'])]
    private Collection $snapshots;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $analysisCount = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAnalyzedAt = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: SnapshotPeriod::class)]
    private ?SnapshotPeriod $snapshotPeriod = null;

    // Lead type - website or business without web
    #[ORM\Column(type: Types::STRING, length: 30, enumType: LeadType::class, options: ['default' => 'website'])]
    private LeadType $type = LeadType::WEBSITE;

    #[ORM\Column(options: ['default' => true])]
    private bool $hasWebsite = true;

    // Company relationship
    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'leads')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    // Discovery profile used for this lead
    #[ORM\ManyToOne(targetEntity: DiscoveryProfile::class, inversedBy: 'leads')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DiscoveryProfile $discoveryProfile = null;

    // Company identification (denormalized for quick access)
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ico = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    // Extracted contact information
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $address = null;

    // Technology detection
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $detectedCms = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $detectedTechnologies = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $socialMedia = null;

    public function __construct()
    {
        $this->analyses = new ArrayCollection();
        $this->snapshots = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $this->stripUtmParameters($url);

        return $this;
    }

    private function stripUtmParameters(string $url): string
    {
        $parsed = parse_url($url);

        if (!isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $params);

        $utmPrefixes = ['utm_', 'gclid', 'fbclid', 'msclkid'];
        $filteredParams = array_filter($params, function (string $key) use ($utmPrefixes): bool {
            foreach ($utmPrefixes as $prefix) {
                if (str_starts_with(strtolower($key), $prefix)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);

        $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }

        if (isset($parsed['path'])) {
            $baseUrl .= $parsed['path'];
        }

        if (!empty($filteredParams)) {
            $baseUrl .= '?' . http_build_query($filteredParams);
        }

        if (isset($parsed['fragment'])) {
            $baseUrl .= '#' . $parsed['fragment'];
        }

        return $baseUrl;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getSource(): LeadSource
    {
        return $this->source;
    }

    public function setSource(LeadSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getAffiliate(): ?Affiliate
    {
        return $this->affiliate;
    }

    public function setAffiliate(?Affiliate $affiliate): static
    {
        $this->affiliate = $affiliate;

        return $this;
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

    public function getStatus(): LeadStatus
    {
        return $this->status;
    }

    public function setStatus(LeadStatus $status): static
    {
        $this->status = $status;

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

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function addMetadata(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

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

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function setAnalyzedAt(?\DateTimeImmutable $analyzedAt): static
    {
        $this->analyzedAt = $analyzedAt;

        return $this;
    }

    public function getDoneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function setDoneAt(?\DateTimeImmutable $doneAt): static
    {
        $this->doneAt = $doneAt;

        return $this;
    }

    public function getDealAt(): ?\DateTimeImmutable
    {
        return $this->dealAt;
    }

    public function setDealAt(?\DateTimeImmutable $dealAt): static
    {
        $this->dealAt = $dealAt;

        return $this;
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

    public function getIndustry(): ?Industry
    {
        return $this->industry;
    }

    public function setIndustry(?Industry $industry): static
    {
        $this->industry = $industry;

        return $this;
    }

    public function getLatestAnalysis(): ?Analysis
    {
        return $this->latestAnalysis;
    }

    public function setLatestAnalysis(?Analysis $latestAnalysis): static
    {
        $this->latestAnalysis = $latestAnalysis;
        $this->score = $latestAnalysis?->getTotalScore();

        return $this;
    }

    /**
     * @return Collection<int, Analysis>
     */
    public function getAnalyses(): Collection
    {
        return $this->analyses;
    }

    /**
     * @return Collection<int, AnalysisSnapshot>
     */
    public function getSnapshots(): Collection
    {
        return $this->snapshots;
    }

    /**
     * Get the latest snapshot for this lead.
     */
    public function getLatestSnapshot(): ?AnalysisSnapshot
    {
        if ($this->snapshots->isEmpty()) {
            return null;
        }

        return $this->snapshots->first() ?: null;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getAnalysisCount(): int
    {
        return $this->analysisCount;
    }

    public function setAnalysisCount(int $analysisCount): static
    {
        $this->analysisCount = $analysisCount;

        return $this;
    }

    public function incrementAnalysisCount(): static
    {
        ++$this->analysisCount;

        return $this;
    }

    public function getLastAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->lastAnalyzedAt;
    }

    public function setLastAnalyzedAt(?\DateTimeImmutable $lastAnalyzedAt): static
    {
        $this->lastAnalyzedAt = $lastAnalyzedAt;

        return $this;
    }

    public function getSnapshotPeriod(): ?SnapshotPeriod
    {
        return $this->snapshotPeriod;
    }

    public function setSnapshotPeriod(?SnapshotPeriod $snapshotPeriod): static
    {
        $this->snapshotPeriod = $snapshotPeriod;

        return $this;
    }

    /**
     * Get the effective snapshot period - either custom or industry default.
     */
    public function getEffectiveSnapshotPeriod(): SnapshotPeriod
    {
        if ($this->snapshotPeriod !== null) {
            return $this->snapshotPeriod;
        }

        if ($this->industry !== null) {
            return $this->industry->getDefaultSnapshotPeriod();
        }

        return SnapshotPeriod::WEEK;
    }

    public function getType(): LeadType
    {
        return $this->type;
    }

    public function setType(LeadType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function hasWebsite(): bool
    {
        return $this->hasWebsite;
    }

    public function setHasWebsite(bool $hasWebsite): static
    {
        $this->hasWebsite = $hasWebsite;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getDiscoveryProfile(): ?DiscoveryProfile
    {
        return $this->discoveryProfile;
    }

    public function setDiscoveryProfile(?DiscoveryProfile $discoveryProfile): static
    {
        $this->discoveryProfile = $discoveryProfile;

        return $this;
    }

    public function getIco(): ?string
    {
        return $this->ico;
    }

    public function setIco(?string $ico): static
    {
        $this->ico = $ico;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getDetectedCms(): ?string
    {
        return $this->detectedCms;
    }

    public function setDetectedCms(?string $detectedCms): static
    {
        $this->detectedCms = $detectedCms;

        return $this;
    }

    /** @return array<string>|null */
    public function getDetectedTechnologies(): ?array
    {
        return $this->detectedTechnologies;
    }

    /** @param array<string>|null $detectedTechnologies */
    public function setDetectedTechnologies(?array $detectedTechnologies): static
    {
        $this->detectedTechnologies = $detectedTechnologies;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getSocialMedia(): ?array
    {
        return $this->socialMedia;
    }

    /** @param array<string, string>|null $socialMedia */
    public function setSocialMedia(?array $socialMedia): static
    {
        $this->socialMedia = $socialMedia;

        return $this;
    }

    public function __toString(): string
    {
        return $this->domain ?? $this->url ?? (string) $this->id;
    }
}
