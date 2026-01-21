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
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_code_unique', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'users_email_unique', columns: ['email'])]
#[ORM\Index(name: 'users_name_idx', columns: ['name'])]
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
    'code' => 'exact',
    'email' => 'exact',
    'name' => 'partial',
])]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    #[Assert\Regex(pattern: '/^[a-z0-9_-]+$/', message: 'Code must contain only lowercase letters, numbers, underscores, and hyphens')]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    // === VZTAHY ===

    /** @var Collection<int, Lead> */
    #[ORM\OneToMany(targetEntity: Lead::class, mappedBy: 'user')]
    private Collection $leads;

    /** @var Collection<int, IndustryBenchmark> */
    #[ORM\OneToMany(targetEntity: IndustryBenchmark::class, mappedBy: 'user')]
    private Collection $industryBenchmarks;

    /** @var Collection<int, UserAnalyzerConfig> */
    #[ORM\OneToMany(targetEntity: UserAnalyzerConfig::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $analyzerConfigs;

    /** @var Collection<int, UserCompanyNote> */
    #[ORM\OneToMany(targetEntity: UserCompanyNote::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $companyNotes;

    /** @var Collection<int, MonitoredDomainSubscription> */
    #[ORM\OneToMany(targetEntity: MonitoredDomainSubscription::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $monitoredDomainSubscriptions;

    /** @var Collection<int, DemandSignalSubscription> */
    #[ORM\OneToMany(targetEntity: DemandSignalSubscription::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $demandSignalSubscriptions;

    /** @var Collection<int, MarketWatchFilter> */
    #[ORM\OneToMany(targetEntity: MarketWatchFilter::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $marketWatchFilters;

    /** @var Collection<int, EmailTemplate> */
    #[ORM\OneToMany(targetEntity: EmailTemplate::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $emailTemplates;

    // === TIMESTAMPS ===

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->leads = new ArrayCollection();
        $this->industryBenchmarks = new ArrayCollection();
        $this->analyzerConfigs = new ArrayCollection();
        $this->companyNotes = new ArrayCollection();
        $this->monitoredDomainSubscriptions = new ArrayCollection();
        $this->demandSignalSubscriptions = new ArrayCollection();
        $this->marketWatchFilters = new ArrayCollection();
        $this->emailTemplates = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtolower($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /** @param array<string, mixed> $settings */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;

        return $this;
    }

    /** @return Collection<int, Lead> */
    public function getLeads(): Collection
    {
        return $this->leads;
    }

    /** @return Collection<int, IndustryBenchmark> */
    public function getIndustryBenchmarks(): Collection
    {
        return $this->industryBenchmarks;
    }

    /** @return Collection<int, UserAnalyzerConfig> */
    public function getAnalyzerConfigs(): Collection
    {
        return $this->analyzerConfigs;
    }

    /** @return Collection<int, UserCompanyNote> */
    public function getCompanyNotes(): Collection
    {
        return $this->companyNotes;
    }

    /** @return Collection<int, MonitoredDomainSubscription> */
    public function getMonitoredDomainSubscriptions(): Collection
    {
        return $this->monitoredDomainSubscriptions;
    }

    /** @return Collection<int, DemandSignalSubscription> */
    public function getDemandSignalSubscriptions(): Collection
    {
        return $this->demandSignalSubscriptions;
    }

    /** @return Collection<int, MarketWatchFilter> */
    public function getMarketWatchFilters(): Collection
    {
        return $this->marketWatchFilters;
    }

    /** @return Collection<int, EmailTemplate> */
    public function getEmailTemplates(): Collection
    {
        return $this->emailTemplates;
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
}
