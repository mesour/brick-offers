<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalStatus;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use App\Repository\DemandSignalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a demand signal - an active inquiry, tender, job posting, or business change
 * that indicates potential need for services.
 */
#[ORM\Entity(repositoryClass: DemandSignalRepository::class)]
#[ORM\Table(name: 'demand_signals')]
#[ORM\Index(name: 'demand_signals_source_idx', columns: ['source'])]
#[ORM\Index(name: 'demand_signals_type_idx', columns: ['signal_type'])]
#[ORM\Index(name: 'demand_signals_status_idx', columns: ['status'])]
#[ORM\Index(name: 'demand_signals_industry_idx', columns: ['industry'])]
#[ORM\Index(name: 'demand_signals_deadline_idx', columns: ['deadline'])]
#[ORM\Index(name: 'demand_signals_published_at_idx', columns: ['published_at'])]
#[ORM\Index(name: 'demand_signals_ico_idx', columns: ['ico'])]
#[ORM\Index(name: 'demand_signals_user_idx', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'demand_signals_source_external_id', columns: ['source', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Patch(),
    ],
    order: ['publishedAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'source' => 'exact',
    'signalType' => 'exact',
    'status' => 'exact',
    'industry' => 'exact',
    'ico' => 'exact',
    'companyName' => 'partial',
    'title' => 'partial',
])]
#[ApiFilter(DateFilter::class, properties: ['deadline', 'publishedAt'])]
class DemandSignal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * User who crawled/created this signal (nullable for shared signals).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /**
     * Whether this signal is shared (visible to all users matching their filters).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isShared = true;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: DemandSignalSource::class)]
    private DemandSignalSource $source;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: DemandSignalType::class)]
    private DemandSignalType $signalType;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DemandSignalStatus::class)]
    private DemandSignalStatus $status = DemandSignalStatus::NEW;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Company identification
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ico = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    // Contact information
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactPerson = null;

    // Value and budget
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $valueMax = null;

    #[ORM\Column(length: 3, options: ['default' => 'CZK'])]
    private string $currency = 'CZK';

    // Classification
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    // Timing
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    // Source reference
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $sourceUrl = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    // Conversion tracking
    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $convertedLead = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $convertedAt = null;

    // Timestamps
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function isShared(): bool
    {
        return $this->isShared;
    }

    public function setIsShared(bool $isShared): static
    {
        $this->isShared = $isShared;

        return $this;
    }

    public function getSource(): DemandSignalSource
    {
        return $this->source;
    }

    public function setSource(DemandSignalSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSignalType(): DemandSignalType
    {
        return $this->signalType;
    }

    public function setSignalType(DemandSignalType $signalType): static
    {
        $this->signalType = $signalType;

        return $this;
    }

    public function getStatus(): DemandSignalStatus
    {
        return $this->status;
    }

    public function setStatus(DemandSignalStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

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

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;

        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getValueMax(): ?string
    {
        return $this->valueMax;
    }

    public function setValueMax(?string $valueMax): static
    {
        $this->valueMax = $valueMax;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTimeImmutable $deadline): static
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

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

    public function getConvertedLead(): ?Lead
    {
        return $this->convertedLead;
    }

    public function setConvertedLead(?Lead $convertedLead): static
    {
        $this->convertedLead = $convertedLead;
        if ($convertedLead !== null) {
            $this->convertedAt = new \DateTimeImmutable();
            $this->status = DemandSignalStatus::CONVERTED;
        }

        return $this;
    }

    public function getConvertedAt(): ?\DateTimeImmutable
    {
        return $this->convertedAt;
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

    /**
     * Check if the deadline has passed.
     */
    public function isExpired(): bool
    {
        if ($this->deadline === null) {
            return false;
        }

        return $this->deadline < new \DateTimeImmutable();
    }

    /**
     * Mark as expired if deadline has passed.
     */
    public function checkExpiration(): void
    {
        if ($this->isExpired() && $this->status->isActive()) {
            $this->status = DemandSignalStatus::EXPIRED;
        }
    }
}
