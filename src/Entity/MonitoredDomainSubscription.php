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
use App\Enum\ChangeSignificance;
use App\Repository\MonitoredDomainSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a user's subscription to a monitored domain.
 * Controls which snapshot types the user wants to track and alert preferences.
 */
#[ORM\Entity(repositoryClass: MonitoredDomainSubscriptionRepository::class)]
#[ORM\Table(name: 'monitored_domain_subscriptions')]
#[ORM\UniqueConstraint(name: 'monitored_domain_subscriptions_user_domain_unique', columns: ['user_id', 'monitored_domain_id'])]
#[ORM\Index(name: 'monitored_domain_subscriptions_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'monitored_domain_subscriptions_domain_idx', columns: ['monitored_domain_id'])]
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
    'monitoredDomain.id' => 'exact',
    'monitoredDomain.domain' => 'partial',
    'alertOnChange' => 'exact',
])]
class MonitoredDomainSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'monitoredDomainSubscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class, inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MonitoredDomain $monitoredDomain;

    /**
     * Which snapshot types to track.
     * Example: ["portfolio", "pricing", "services"]
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshotTypes = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $alertOnChange = true;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ChangeSignificance::class, options: ['default' => 'low'])]
    private ChangeSignificance $minSignificance = ChangeSignificance::LOW;

    /**
     * User-specific notes about this subscription.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Virtual property for form input (not persisted).
     * Used in admin to allow entering domain name directly.
     */
    private ?string $domainInput = null;

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

    public function getMonitoredDomain(): MonitoredDomain
    {
        return $this->monitoredDomain;
    }

    public function setMonitoredDomain(MonitoredDomain $monitoredDomain): static
    {
        $this->monitoredDomain = $monitoredDomain;

        return $this;
    }

    /** @return array<string> */
    public function getSnapshotTypes(): array
    {
        return $this->snapshotTypes;
    }

    /** @param array<string> $snapshotTypes */
    public function setSnapshotTypes(array $snapshotTypes): static
    {
        $this->snapshotTypes = array_values(array_unique($snapshotTypes));

        return $this;
    }

    public function hasSnapshotType(string $type): bool
    {
        return empty($this->snapshotTypes) || in_array($type, $this->snapshotTypes, true);
    }

    public function isAlertOnChange(): bool
    {
        return $this->alertOnChange;
    }

    public function setAlertOnChange(bool $alertOnChange): static
    {
        $this->alertOnChange = $alertOnChange;

        return $this;
    }

    public function getMinSignificance(): ChangeSignificance
    {
        return $this->minSignificance;
    }

    public function setMinSignificance(ChangeSignificance $minSignificance): static
    {
        $this->minSignificance = $minSignificance;

        return $this;
    }

    /**
     * Check if a change significance meets the user's alert threshold.
     */
    public function shouldAlertForSignificance(ChangeSignificance $significance): bool
    {
        if (!$this->alertOnChange) {
            return false;
        }

        return $significance->getWeight() >= $this->minSignificance->getWeight();
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getDomainInput(): ?string
    {
        // Use isset() to handle uninitialized property (new entities)
        if (isset($this->monitoredDomain)) {
            return $this->monitoredDomain->getDomain();
        }

        return $this->domainInput;
    }

    public function setDomainInput(?string $domainInput): static
    {
        $this->domainInput = $domainInput;

        return $this;
    }
}
