<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use App\Repository\ProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Proposal represents a generated proposal/design for a lead.
 *
 * Proposals can be AI-generated and recycled between users if:
 * - AI-generated (is_ai_generated = true)
 * - Not customized by user (is_customized = false)
 * - Rejected status
 * - recyclable flag is true
 */
#[ORM\Entity(repositoryClass: ProposalRepository::class)]
#[ORM\Table(name: 'proposals')]
#[ORM\UniqueConstraint(name: 'proposals_lead_type_unique', columns: ['lead_id', 'type'])]
#[ORM\Index(name: 'proposals_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'proposals_user_status_idx', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'proposals_status_idx', columns: ['status'])]
#[ORM\Index(name: 'proposals_type_idx', columns: ['type'])]
#[ORM\Index(name: 'proposals_industry_idx', columns: ['industry'])]
#[ORM\Index(name: 'proposals_recyclable_idx', columns: ['status', 'recyclable', 'is_customized'])]
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
    'user.code' => 'exact',
    'lead.id' => 'exact',
    'status' => 'exact',
    'type' => 'exact',
    'industry' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isAiGenerated', 'isCustomized', 'recyclable'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'expiresAt'])]
class Proposal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * Current owner of this proposal.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Associated lead (nullable - can exist without lead initially).
     */
    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    /**
     * Source analysis used for generation.
     */
    #[ORM\ManyToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Analysis $analysis = null;

    /**
     * Original owner (for recycling tracking).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $originalUser = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: ProposalType::class)]
    private ProposalType $type;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ProposalStatus::class)]
    private ProposalStatus $status = ProposalStatus::GENERATING;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /**
     * Main content (HTML or Markdown).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    /**
     * Short summary for email inclusion.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /**
     * Generated outputs URLs.
     * Example: {"html_url": "...", "screenshot_url": "...", "pdf_url": "..."}
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $outputs = [];

    /**
     * AI generation metadata.
     * Example: {"model": "claude-3-opus", "tokens_used": 1234, "generation_time_ms": 5600}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $aiMetadata = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isAiGenerated = true;

    /**
     * Whether user has customized the content (prevents recycling).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCustomized = false;

    /**
     * Whether this proposal can be recycled to another user.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $recyclable = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recycledAt = null;

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

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): static
    {
        $this->analysis = $analysis;

        return $this;
    }

    public function getOriginalUser(): ?User
    {
        return $this->originalUser;
    }

    public function setOriginalUser(?User $originalUser): static
    {
        $this->originalUser = $originalUser;

        return $this;
    }

    public function getType(): ProposalType
    {
        return $this->type;
    }

    public function setType(ProposalType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function setStatus(ProposalStatus $status): static
    {
        $this->status = $status;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** @return array<string, string> */
    public function getOutputs(): array
    {
        return $this->outputs;
    }

    /** @param array<string, string> $outputs */
    public function setOutputs(array $outputs): static
    {
        $this->outputs = $outputs;

        return $this;
    }

    public function getOutput(string $key): ?string
    {
        return $this->outputs[$key] ?? null;
    }

    public function setOutput(string $key, string $url): static
    {
        $this->outputs[$key] = $url;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAiMetadata(): array
    {
        return $this->aiMetadata;
    }

    /** @param array<string, mixed> $aiMetadata */
    public function setAiMetadata(array $aiMetadata): static
    {
        $this->aiMetadata = $aiMetadata;

        return $this;
    }

    public function isAiGenerated(): bool
    {
        return $this->isAiGenerated;
    }

    public function setIsAiGenerated(bool $isAiGenerated): static
    {
        $this->isAiGenerated = $isAiGenerated;

        return $this;
    }

    public function isCustomized(): bool
    {
        return $this->isCustomized;
    }

    public function setIsCustomized(bool $isCustomized): static
    {
        $this->isCustomized = $isCustomized;
        // Customized proposals cannot be recycled
        if ($isCustomized) {
            $this->recyclable = false;
        }

        return $this;
    }

    public function isRecyclable(): bool
    {
        return $this->recyclable;
    }

    public function setRecyclable(bool $recyclable): static
    {
        $this->recyclable = $recyclable;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRecycledAt(): ?\DateTimeImmutable
    {
        return $this->recycledAt;
    }

    public function setRecycledAt(?\DateTimeImmutable $recycledAt): static
    {
        $this->recycledAt = $recycledAt;

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
     * Check if this proposal can be recycled to another user.
     */
    public function canBeRecycled(): bool
    {
        return $this->status->canRecycle()
            && $this->isAiGenerated
            && !$this->isCustomized
            && $this->recyclable;
    }

    /**
     * Check if the proposal has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Mark the proposal as approved.
     */
    public function approve(): static
    {
        if (!$this->status->canApprove()) {
            throw new \LogicException(sprintf('Cannot approve proposal in status %s', $this->status->value));
        }

        $this->status = ProposalStatus::APPROVED;

        return $this;
    }

    /**
     * Mark the proposal as rejected.
     */
    public function reject(): static
    {
        if (!$this->status->canReject()) {
            throw new \LogicException(sprintf('Cannot reject proposal in status %s', $this->status->value));
        }

        $this->status = ProposalStatus::REJECTED;

        return $this;
    }

    /**
     * Recycle this proposal to a new user and lead.
     */
    public function recycleTo(User $newUser, ?Lead $newLead = null): static
    {
        if (!$this->canBeRecycled()) {
            throw new \LogicException('This proposal cannot be recycled');
        }

        // Track original owner
        if ($this->originalUser === null) {
            $this->originalUser = $this->user;
        }

        $this->user = $newUser;
        $this->lead = $newLead;
        $this->status = ProposalStatus::DRAFT;
        $this->recycledAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        $label = $this->lead?->getDomain() ?? 'Proposal';

        return sprintf('%s - %s', $label, $this->type->value);
    }
}
