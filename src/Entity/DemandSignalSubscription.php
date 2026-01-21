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
use App\Enum\SubscriptionStatus;
use App\Repository\DemandSignalSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a user's view/subscription to a shared demand signal.
 * Tracks per-user status, notes, and conversion.
 */
#[ORM\Entity(repositoryClass: DemandSignalSubscriptionRepository::class)]
#[ORM\Table(name: 'demand_signal_subscriptions')]
#[ORM\UniqueConstraint(name: 'demand_signal_subscriptions_user_signal_unique', columns: ['user_id', 'demand_signal_id'])]
#[ORM\Index(name: 'demand_signal_subscriptions_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'demand_signal_subscriptions_signal_idx', columns: ['demand_signal_id'])]
#[ORM\Index(name: 'demand_signal_subscriptions_status_idx', columns: ['status'])]
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
    'demandSignal.id' => 'exact',
    'status' => 'exact',
])]
class DemandSignalSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'demandSignalSubscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: DemandSignal::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DemandSignal $demandSignal;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SubscriptionStatus::class, options: ['default' => 'new'])]
    private SubscriptionStatus $status = SubscriptionStatus::NEW;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Lead created from this signal (if converted).
     */
    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $convertedLead = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $convertedAt = null;

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

    public function getDemandSignal(): DemandSignal
    {
        return $this->demandSignal;
    }

    public function setDemandSignal(DemandSignal $demandSignal): static
    {
        $this->demandSignal = $demandSignal;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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
            $this->status = SubscriptionStatus::CONVERTED;
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
     * Mark as reviewed.
     */
    public function markReviewed(): static
    {
        if ($this->status === SubscriptionStatus::NEW) {
            $this->status = SubscriptionStatus::REVIEWED;
        }

        return $this;
    }

    /**
     * Dismiss the signal.
     */
    public function dismiss(): static
    {
        $this->status = SubscriptionStatus::DISMISSED;

        return $this;
    }
}
