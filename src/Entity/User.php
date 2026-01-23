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
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_code_unique', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'users_email_unique', columns: ['email'])]
#[ORM\Index(name: 'users_name_idx', columns: ['name'])]
#[ORM\Index(name: 'users_admin_account_idx', columns: ['admin_account_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Uživatel s tímto kódem již existuje.')]
#[UniqueEntity(fields: ['email'], message: 'Uživatel s tímto emailem již existuje.')]
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
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_USER = 'ROLE_USER';

    // Permission scopes
    public const PERMISSION_LEADS_READ = 'leads:read';
    public const PERMISSION_LEADS_WRITE = 'leads:write';
    public const PERMISSION_LEADS_DELETE = 'leads:delete';
    public const PERMISSION_LEADS_ANALYZE = 'leads:analyze';
    public const PERMISSION_OFFERS_READ = 'offers:read';
    public const PERMISSION_OFFERS_WRITE = 'offers:write';
    public const PERMISSION_OFFERS_APPROVE = 'offers:approve';
    public const PERMISSION_OFFERS_SEND = 'offers:send';
    public const PERMISSION_PROPOSALS_READ = 'proposals:read';
    public const PERMISSION_PROPOSALS_APPROVE = 'proposals:approve';
    public const PERMISSION_PROPOSALS_REJECT = 'proposals:reject';
    public const PERMISSION_ANALYSIS_READ = 'analysis:read';
    public const PERMISSION_ANALYSIS_TRIGGER = 'analysis:trigger';
    public const PERMISSION_COMPETITORS_READ = 'competitors:read';
    public const PERMISSION_COMPETITORS_MANAGE = 'competitors:manage';
    public const PERMISSION_STATS_READ = 'stats:read';
    public const PERMISSION_SETTINGS_READ = 'settings:read';
    public const PERMISSION_SETTINGS_WRITE = 'settings:write';
    public const PERMISSION_USERS_READ = 'users:read';
    public const PERMISSION_USERS_MANAGE = 'users:manage';

    // Permission templates
    public const TEMPLATE_MANAGER = 'manager';
    public const TEMPLATE_APPROVER = 'approver';
    public const TEMPLATE_ANALYST = 'analyst';
    public const TEMPLATE_FULL = 'full';

    /** @var array<string, array<string>> */
    public const PERMISSION_TEMPLATES = [
        self::TEMPLATE_MANAGER => [
            self::PERMISSION_LEADS_READ,
            self::PERMISSION_OFFERS_READ,
            self::PERMISSION_STATS_READ,
            self::PERMISSION_COMPETITORS_READ,
        ],
        self::TEMPLATE_APPROVER => [
            self::PERMISSION_LEADS_READ,
            self::PERMISSION_OFFERS_READ,
            self::PERMISSION_OFFERS_APPROVE,
            self::PERMISSION_OFFERS_SEND,
            self::PERMISSION_PROPOSALS_READ,
            self::PERMISSION_PROPOSALS_APPROVE,
            self::PERMISSION_PROPOSALS_REJECT,
        ],
        self::TEMPLATE_ANALYST => [
            self::PERMISSION_LEADS_READ,
            self::PERMISSION_LEADS_WRITE,
            self::PERMISSION_ANALYSIS_READ,
            self::PERMISSION_ANALYSIS_TRIGGER,
            self::PERMISSION_STATS_READ,
        ],
        self::TEMPLATE_FULL => [
            self::PERMISSION_LEADS_READ,
            self::PERMISSION_LEADS_WRITE,
            self::PERMISSION_LEADS_DELETE,
            self::PERMISSION_LEADS_ANALYZE,
            self::PERMISSION_OFFERS_READ,
            self::PERMISSION_OFFERS_WRITE,
            self::PERMISSION_OFFERS_APPROVE,
            self::PERMISSION_OFFERS_SEND,
            self::PERMISSION_PROPOSALS_READ,
            self::PERMISSION_PROPOSALS_APPROVE,
            self::PERMISSION_PROPOSALS_REJECT,
            self::PERMISSION_ANALYSIS_READ,
            self::PERMISSION_ANALYSIS_TRIGGER,
            self::PERMISSION_COMPETITORS_READ,
            self::PERMISSION_COMPETITORS_MANAGE,
            self::PERMISSION_STATS_READ,
            self::PERMISSION_SETTINGS_READ,
            self::PERMISSION_SETTINGS_WRITE,
            self::PERMISSION_USERS_READ,
        ],
    ];

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    /**
     * Plain password for form handling (not persisted).
     */
    private ?string $plainPassword = null;

    /** @var array<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [self::ROLE_USER];

    /**
     * Admin account for sub-users. NULL = this is an admin account.
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'subAccounts')]
    #[ORM\JoinColumn(name: 'admin_account_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $adminAccount = null;

    /**
     * Sub-accounts managed by this admin.
     *
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'adminAccount', cascade: ['persist'])]
    private Collection $subAccounts;

    /**
     * Granular permissions for non-admin users.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /**
     * System limits set by CLI for tenant (admin only).
     * Example: {"maxLeadsPerMonth": 1000, "maxEmailsPerDay": 100}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $limits = [];

    /**
     * Industry for this user (set via CLI, sub-users inherit from admin).
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    /**
     * Domain patterns to exclude from discovery results.
     * Supports wildcards: *.example.com, example.*, *example*
     * Sub-users inherit patterns from admin account.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $excludedDomains = [];

    // === VZTAHY ===

    /** @var Collection<int, Lead> */
    #[ORM\OneToMany(targetEntity: Lead::class, mappedBy: 'user')]
    private Collection $leads;

    /** @var Collection<int, IndustryBenchmark> */
    #[ORM\OneToMany(targetEntity: IndustryBenchmark::class, mappedBy: 'user')]
    private Collection $industryBenchmarks;

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

    /** @var Collection<int, DiscoveryProfile> */
    #[ORM\OneToMany(targetEntity: DiscoveryProfile::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $discoveryProfiles;

    // === TIMESTAMPS ===

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->leads = new ArrayCollection();
        $this->industryBenchmarks = new ArrayCollection();
        $this->companyNotes = new ArrayCollection();
        $this->monitoredDomainSubscriptions = new ArrayCollection();
        $this->demandSignalSubscriptions = new ArrayCollection();
        $this->marketWatchFilters = new ArrayCollection();
        $this->emailTemplates = new ArrayCollection();
        $this->discoveryProfiles = new ArrayCollection();
        $this->subAccounts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
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

    // UserInterface implementation

    public function getUserIdentifier(): string
    {
        return $this->email ?? $this->code;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    /** @return array<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // Guarantee every user at least has ROLE_USER
        $roles[] = self::ROLE_USER;

        return array_unique($roles);
    }

    /** @param array<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear the plain password after authentication
        $this->plainPassword = null;
    }

    // Admin/Sub-account methods

    public function getAdminAccount(): ?User
    {
        return $this->adminAccount;
    }

    public function setAdminAccount(?User $adminAccount): static
    {
        $this->adminAccount = $adminAccount;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getSubAccounts(): Collection
    {
        return $this->subAccounts;
    }

    public function addSubAccount(User $subAccount): static
    {
        if (!$this->subAccounts->contains($subAccount)) {
            $this->subAccounts->add($subAccount);
            $subAccount->setAdminAccount($this);
        }

        return $this;
    }

    public function removeSubAccount(User $subAccount): static
    {
        if ($this->subAccounts->removeElement($subAccount)) {
            if ($subAccount->getAdminAccount() === $this) {
                $subAccount->setAdminAccount(null);
            }
        }

        return $this;
    }

    /**
     * Check if this user is a tenant admin (no parent admin account and has ROLE_ADMIN).
     */
    public function isAdmin(): bool
    {
        return $this->adminAccount === null && in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    /**
     * Check if this user is a sub-user (has parent admin account).
     */
    public function isSubUser(): bool
    {
        return $this->adminAccount !== null;
    }

    /**
     * Get the admin account for this user (returns self if already admin).
     */
    public function getAdminOrSelf(): User
    {
        return $this->adminAccount ?? $this;
    }

    /**
     * Get all users in the same tenant (admin + all sub-accounts).
     *
     * @return array<User>
     */
    public function getTenantUsers(): array
    {
        $admin = $this->getAdminOrSelf();
        $users = [$admin];

        foreach ($admin->getSubAccounts() as $subAccount) {
            $users[] = $subAccount;
        }

        return $users;
    }

    // Permissions

    /** @return array<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /** @param array<string> $permissions */
    public function setPermissions(array $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Apply a permission template.
     */
    public function applyPermissionTemplate(string $template): static
    {
        if (!isset(self::PERMISSION_TEMPLATES[$template])) {
            throw new \InvalidArgumentException(sprintf('Unknown permission template: %s', $template));
        }

        $this->permissions = self::PERMISSION_TEMPLATES[$template];

        return $this;
    }

    /**
     * Check if user has a specific permission.
     * Admins have all permissions, sub-users check the permissions array.
     */
    public function hasPermission(string $permission): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }

    /**
     * Check if user has any of the given permissions.
     *
     * @param array<string> $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (in_array($permission, $this->permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has all of the given permissions.
     *
     * @param array<string> $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!in_array($permission, $this->permissions, true)) {
                return false;
            }
        }

        return true;
    }

    // Limits (for admin accounts only)

    /** @return array<string, mixed> */
    public function getLimits(): array
    {
        // Sub-users inherit limits from admin
        if ($this->adminAccount !== null) {
            return $this->adminAccount->getLimits();
        }

        return $this->limits;
    }

    /** @param array<string, mixed> $limits */
    public function setLimits(array $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    public function getLimit(string $key, mixed $default = null): mixed
    {
        $limits = $this->getLimits();

        return $limits[$key] ?? $default;
    }

    public function setLimit(string $key, mixed $value): static
    {
        $this->limits[$key] = $value;

        return $this;
    }

    // Industry (set via CLI, inherited by sub-users)

    /**
     * Get industry for this user.
     * Sub-users inherit from admin account.
     */
    public function getIndustry(): ?Industry
    {
        // Sub-users inherit industry from admin
        if ($this->adminAccount !== null) {
            return $this->adminAccount->getIndustry();
        }

        return $this->industry;
    }

    /**
     * Set industry for this user.
     */
    public function setIndustry(?Industry $industry): static
    {
        $this->industry = $industry;

        return $this;
    }

    /**
     * Check if user has an industry set.
     */
    public function hasIndustry(): bool
    {
        return $this->getIndustry() !== null;
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

    // Excluded domains (for discovery filtering)

    /**
     * Get excluded domain patterns.
     * Sub-users inherit patterns from admin account.
     *
     * @return array<string>
     */
    public function getExcludedDomains(): array
    {
        // Sub-users inherit excluded domains from admin
        if ($this->adminAccount !== null) {
            return array_values(array_unique(array_merge(
                $this->adminAccount->getExcludedDomains(),
                $this->excludedDomains
            )));
        }

        return $this->excludedDomains;
    }

    /**
     * Get only this user's excluded domains (without inheritance).
     *
     * @return array<string>
     */
    public function getOwnExcludedDomains(): array
    {
        return $this->excludedDomains;
    }

    /**
     * @param array<string> $excludedDomains
     */
    public function setExcludedDomains(array $excludedDomains): static
    {
        $this->excludedDomains = $excludedDomains;

        return $this;
    }

    /**
     * Get excluded domains as text (one pattern per line).
     * For admin UI textarea.
     */
    public function getExcludedDomainsText(): string
    {
        return implode("\n", $this->excludedDomains);
    }

    /**
     * Set excluded domains from text (one pattern per line).
     * For admin UI textarea.
     */
    public function setExcludedDomainsText(?string $text): static
    {
        if ($text === null || $text === '') {
            $this->excludedDomains = [];
        } else {
            $lines = explode("\n", $text);
            $this->excludedDomains = array_values(array_filter(array_map('trim', $lines)));
        }

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

    /** @return Collection<int, DiscoveryProfile> */
    public function getDiscoveryProfiles(): Collection
    {
        return $this->discoveryProfiles;
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
