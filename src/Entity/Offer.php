<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\OfferStatus;
use App\Repository\OfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Offer represents an email offer to be sent to a lead.
 *
 * Combines data from Lead, Analysis, and Proposal into a final email.
 */
#[ORM\Entity(repositoryClass: OfferRepository::class)]
#[ORM\Table(name: 'offers')]
#[ORM\Index(name: 'offers_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'offers_user_status_idx', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'offers_lead_idx', columns: ['lead_id'])]
#[ORM\Index(name: 'offers_status_idx', columns: ['status'])]
#[ORM\Index(name: 'offers_status_sent_idx', columns: ['status', 'sent_at'])]
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
])]
#[ApiFilter(DateFilter::class, properties: ['sentAt', 'createdAt'])]
class Offer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * Owner of this offer (via Lead).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Target lead for this offer.
     */
    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lead $lead;

    /**
     * Associated proposal (if any).
     */
    #[ORM\ManyToOne(targetEntity: Proposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Proposal $proposal = null;

    /**
     * Source analysis used for generation.
     */
    #[ORM\ManyToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Analysis $analysis = null;

    /**
     * Email template used for generation.
     */
    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailTemplate $emailTemplate = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OfferStatus::class)]
    private OfferStatus $status = OfferStatus::DRAFT;

    /**
     * Final email subject.
     */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private string $subject = '';

    /**
     * Final email body (HTML).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /**
     * Plain text version of the email body.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $plainTextBody = null;

    /**
     * Unique tracking token for open/click tracking.
     */
    #[ORM\Column(length: 100, unique: true)]
    private string $trackingToken;

    /**
     * Recipient email address.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $recipientEmail = '';

    /**
     * Recipient name (if known).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipientName = null;

    /**
     * AI personalization metadata.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $aiMetadata = [];

    /**
     * Rejection reason (if rejected).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /**
     * User who approved this offer.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $clickedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $convertedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->trackingToken = bin2hex(random_bytes(32));
    }

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

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function setLead(Lead $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function getProposal(): ?Proposal
    {
        return $this->proposal;
    }

    public function setProposal(?Proposal $proposal): static
    {
        $this->proposal = $proposal;

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

    public function getEmailTemplate(): ?EmailTemplate
    {
        return $this->emailTemplate;
    }

    public function setEmailTemplate(?EmailTemplate $emailTemplate): static
    {
        $this->emailTemplate = $emailTemplate;

        return $this;
    }

    public function getStatus(): OfferStatus
    {
        return $this->status;
    }

    public function setStatus(OfferStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getPlainTextBody(): ?string
    {
        return $this->plainTextBody;
    }

    public function setPlainTextBody(?string $plainTextBody): static
    {
        $this->plainTextBody = $plainTextBody;

        return $this;
    }

    public function getTrackingToken(): string
    {
        return $this->trackingToken;
    }

    public function setTrackingToken(string $trackingToken): static
    {
        $this->trackingToken = $trackingToken;

        return $this;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): static
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $this->recipientName = $recipientName;

        return $this;
    }

    /**
     * Get recipient domain from email.
     */
    public function getRecipientDomain(): string
    {
        $parts = explode('@', $this->recipientEmail);

        return $parts[1] ?? '';
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

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;

        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeImmutable $openedAt): static
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getClickedAt(): ?\DateTimeImmutable
    {
        return $this->clickedAt;
    }

    public function setClickedAt(?\DateTimeImmutable $clickedAt): static
    {
        $this->clickedAt = $clickedAt;

        return $this;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeImmutable $respondedAt): static
    {
        $this->respondedAt = $respondedAt;

        return $this;
    }

    public function getConvertedAt(): ?\DateTimeImmutable
    {
        return $this->convertedAt;
    }

    public function setConvertedAt(?\DateTimeImmutable $convertedAt): static
    {
        $this->convertedAt = $convertedAt;

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
     * Submit offer for approval.
     */
    public function submitForApproval(): static
    {
        if (!$this->status->canSubmitForApproval()) {
            throw new \LogicException(sprintf('Cannot submit offer for approval in status %s', $this->status->value));
        }

        $this->status = OfferStatus::PENDING_APPROVAL;

        return $this;
    }

    /**
     * Approve the offer.
     */
    public function approve(User $approver): static
    {
        if (!$this->status->canApprove()) {
            throw new \LogicException(sprintf('Cannot approve offer in status %s', $this->status->value));
        }

        $this->status = OfferStatus::APPROVED;
        $this->approvedBy = $approver;
        $this->approvedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Reject the offer.
     */
    public function reject(string $reason = ''): static
    {
        if (!$this->status->canReject()) {
            throw new \LogicException(sprintf('Cannot reject offer in status %s', $this->status->value));
        }

        $this->status = OfferStatus::REJECTED;
        $this->rejectionReason = $reason ?: null;

        return $this;
    }

    /**
     * Mark offer as sent.
     */
    public function markSent(): static
    {
        if (!$this->status->canSend()) {
            throw new \LogicException(sprintf('Cannot send offer in status %s', $this->status->value));
        }

        $this->status = OfferStatus::SENT;
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Track email open.
     */
    public function trackOpen(): static
    {
        if (!$this->status->isSent()) {
            return $this;
        }

        if ($this->openedAt === null) {
            $this->openedAt = new \DateTimeImmutable();
        }

        if ($this->status === OfferStatus::SENT) {
            $this->status = OfferStatus::OPENED;
        }

        return $this;
    }

    /**
     * Track link click.
     */
    public function trackClick(): static
    {
        if (!$this->status->isSent()) {
            return $this;
        }

        if ($this->clickedAt === null) {
            $this->clickedAt = new \DateTimeImmutable();
        }

        // Auto-track open if not already tracked
        if ($this->openedAt === null) {
            $this->openedAt = new \DateTimeImmutable();
        }

        if ($this->status === OfferStatus::SENT || $this->status === OfferStatus::OPENED) {
            $this->status = OfferStatus::CLICKED;
        }

        return $this;
    }

    /**
     * Mark as responded.
     */
    public function markResponded(): static
    {
        if (!$this->status->isSent()) {
            throw new \LogicException(sprintf('Cannot mark as responded in status %s', $this->status->value));
        }

        $this->respondedAt = new \DateTimeImmutable();
        $this->status = OfferStatus::RESPONDED;

        return $this;
    }

    /**
     * Mark as converted.
     */
    public function markConverted(): static
    {
        if (!$this->status->isSent()) {
            throw new \LogicException(sprintf('Cannot mark as converted in status %s', $this->status->value));
        }

        $this->convertedAt = new \DateTimeImmutable();
        $this->status = OfferStatus::CONVERTED;

        return $this;
    }

    public function __toString(): string
    {
        return $this->subject ?? sprintf('Offer #%s', $this->id?->toBase58() ?? 'new');
    }
}
