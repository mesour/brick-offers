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
use App\Enum\IssueCategory;
use App\Repository\UserAnalyzerConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User-specific analyzer configuration.
 * Allows users to enable/disable specific analysis categories and customize thresholds.
 */
#[ORM\Entity(repositoryClass: UserAnalyzerConfigRepository::class)]
#[ORM\Table(name: 'user_analyzer_configs')]
#[ORM\UniqueConstraint(name: 'user_analyzer_configs_user_category_unique', columns: ['user_id', 'category'])]
#[ORM\Index(name: 'user_analyzer_configs_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'user_analyzer_configs_enabled_idx', columns: ['enabled'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    order: ['priority' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'user.id' => 'exact',
    'category' => 'exact',
    'enabled' => 'exact',
])]
class UserAnalyzerConfig
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'analyzerConfigs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: IssueCategory::class)]
    private IssueCategory $category;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 5])]
    #[Assert\Range(min: 1, max: 10)]
    private int $priority = 5;

    /**
     * Custom configuration for this analyzer category.
     * Example: {"thresholds": {"ssl_expiry_warning_days": 30}, "ignore_codes": ["HSTS_NOT_SET"]}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = max(1, min(10, $priority));

        return $this;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get a specific config value by key.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set a specific config value.
     */
    public function setConfigValue(string $key, mixed $value): static
    {
        $this->config[$key] = $value;

        return $this;
    }

    /**
     * Get threshold values from config.
     *
     * @return array<string, mixed>
     */
    public function getThresholds(): array
    {
        return $this->config['thresholds'] ?? [];
    }

    /**
     * Get a specific threshold value.
     */
    public function getThreshold(string $key, mixed $default = null): mixed
    {
        return $this->getThresholds()[$key] ?? $default;
    }

    /**
     * Get list of issue codes to ignore.
     *
     * @return array<string>
     */
    public function getIgnoreCodes(): array
    {
        return $this->config['ignore_codes'] ?? [];
    }

    /**
     * Check if a specific issue code should be ignored.
     */
    public function shouldIgnoreCode(string $code): bool
    {
        return in_array($code, $this->getIgnoreCodes(), true);
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
