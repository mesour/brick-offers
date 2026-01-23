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
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use App\Repository\MarketWatchFilterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User-defined filter for automatic demand signal matching.
 * When new signals are crawled, they're matched against user filters to create subscriptions.
 */
#[ORM\Entity(repositoryClass: MarketWatchFilterRepository::class)]
#[ORM\Table(name: 'market_watch_filters')]
#[ORM\Index(name: 'market_watch_filters_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'market_watch_filters_active_idx', columns: ['active'])]
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
    'user.id' => 'exact',
    'active' => 'exact',
    'name' => 'partial',
])]
class MarketWatchFilter
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'marketWatchFilters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Industries to match.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $industries = [];

    /**
     * Regions to match.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $regions = [];

    /**
     * Signal types to match.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $signalTypes = [];

    /**
     * Keywords to match (in title or description).
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $keywords = [];

    /**
     * Keywords to exclude.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $excludeKeywords = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $minValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $maxValue = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    /** @return array<string> */
    public function getIndustries(): array
    {
        return $this->industries;
    }

    /** @param array<string> $industries */
    public function setIndustries(array $industries): static
    {
        $this->industries = array_values(array_unique($industries));

        return $this;
    }

    /** @return array<string> */
    public function getRegions(): array
    {
        return $this->regions;
    }

    /** @param array<string> $regions */
    public function setRegions(array $regions): static
    {
        $this->regions = array_values(array_unique($regions));

        return $this;
    }

    /** @return array<string> */
    public function getSignalTypes(): array
    {
        return $this->signalTypes;
    }

    /** @param array<string> $signalTypes */
    public function setSignalTypes(array $signalTypes): static
    {
        $this->signalTypes = array_values(array_unique($signalTypes));

        return $this;
    }

    /** @return array<string> */
    public function getKeywords(): array
    {
        return $this->keywords;
    }

    /** @param array<string> $keywords */
    public function setKeywords(array $keywords): static
    {
        $this->keywords = array_values(array_unique(array_map('strtolower', $keywords)));

        return $this;
    }

    /** @return array<string> */
    public function getExcludeKeywords(): array
    {
        return $this->excludeKeywords;
    }

    /** @param array<string> $excludeKeywords */
    public function setExcludeKeywords(array $excludeKeywords): static
    {
        $this->excludeKeywords = array_values(array_unique(array_map('strtolower', $excludeKeywords)));

        return $this;
    }

    public function getMinValue(): ?string
    {
        return $this->minValue;
    }

    public function setMinValue(?string $minValue): static
    {
        $this->minValue = $minValue;

        return $this;
    }

    public function getMaxValue(): ?string
    {
        return $this->maxValue;
    }

    public function setMaxValue(?string $maxValue): static
    {
        $this->maxValue = $maxValue;

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

    /**
     * Check if a demand signal matches this filter.
     */
    public function matches(DemandSignal $signal): bool
    {
        // Check industry - use user's industry setting
        $userIndustry = $this->user->getIndustry();
        if ($userIndustry !== null && $signal->getIndustry() !== null) {
            if ($signal->getIndustry() !== $userIndustry) {
                return false;
            }
        }

        // Check region
        if (!empty($this->regions) && $signal->getRegion() !== null) {
            $matchesRegion = false;
            foreach ($this->regions as $region) {
                if (stripos($signal->getRegion(), $region) !== false) {
                    $matchesRegion = true;
                    break;
                }
            }
            if (!$matchesRegion) {
                return false;
            }
        }

        // Check signal type
        if (!empty($this->signalTypes)) {
            if (!in_array($signal->getSignalType()->value, $this->signalTypes, true)) {
                return false;
            }
        }

        // Check value range
        $signalValue = $signal->getValue();
        if ($signalValue !== null) {
            if ($this->minValue !== null && (float) $signalValue < (float) $this->minValue) {
                return false;
            }
            if ($this->maxValue !== null && (float) $signalValue > (float) $this->maxValue) {
                return false;
            }
        }

        // Check keywords
        $searchText = strtolower($signal->getTitle() . ' ' . ($signal->getDescription() ?? ''));

        if (!empty($this->keywords)) {
            $matchesKeyword = false;
            foreach ($this->keywords as $keyword) {
                if (stripos($searchText, $keyword) !== false) {
                    $matchesKeyword = true;
                    break;
                }
            }
            if (!$matchesKeyword) {
                return false;
            }
        }

        // Check exclude keywords
        if (!empty($this->excludeKeywords)) {
            foreach ($this->excludeKeywords as $keyword) {
                if (stripos($searchText, $keyword) !== false) {
                    return false;
                }
            }
        }

        return true;
    }
}
