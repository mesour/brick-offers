<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\EmailBounceType;
use App\Enum\EmailProvider;
use App\Enum\EmailStatus;
use App\Repository\EmailLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Log of sent emails with delivery tracking.
 */
#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_logs')]
#[ORM\Index(name: 'email_logs_user_status_idx', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'email_logs_offer_idx', columns: ['offer_id'])]
#[ORM\Index(name: 'email_logs_message_id_idx', columns: ['message_id'])]
#[ORM\Index(name: 'email_logs_to_email_idx', columns: ['to_email'])]
#[ORM\Index(name: 'email_logs_created_at_idx', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'user.id' => 'exact',
    'user.code' => 'exact',
    'offer.id' => 'exact',
    'status' => 'exact',
    'toEmail' => 'exact',
    'messageId' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['sentAt', 'createdAt'])]
class EmailLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * User who sent this email.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Associated offer (if any).
     */
    #[ORM\ManyToOne(targetEntity: Offer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Offer $offer = null;

    /**
     * Email provider used.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: EmailProvider::class)]
    private EmailProvider $provider;

    /**
     * Provider's message ID for tracking.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    /**
     * Recipient email address.
     */
    #[ORM\Column(length: 255)]
    private string $toEmail;

    /**
     * Recipient name (if known).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    /**
     * Sender email address.
     */
    #[ORM\Column(length: 255)]
    private string $fromEmail;

    /**
     * Email subject.
     */
    #[ORM\Column(length: 500)]
    private string $subject;

    /**
     * Delivery status.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: EmailStatus::class)]
    private EmailStatus $status = EmailStatus::PENDING;

    /**
     * Bounce type (if bounced).
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: EmailBounceType::class)]
    private ?EmailBounceType $bounceType = null;

    /**
     * Bounce/error message.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bounceMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $clickedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bouncedAt = null;

    /**
     * Additional metadata (provider response, headers, etc.).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): static
    {
        $this->offer = $offer;

        return $this;
    }

    public function getProvider(): EmailProvider
    {
        return $this->provider;
    }

    public function setProvider(EmailProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getToEmail(): string
    {
        return $this->toEmail;
    }

    public function setToEmail(string $toEmail): static
    {
        $this->toEmail = $toEmail;

        return $this;
    }

    public function getToName(): ?string
    {
        return $this->toName;
    }

    public function setToName(?string $toName): static
    {
        $this->toName = $toName;

        return $this;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $fromEmail): static
    {
        $this->fromEmail = $fromEmail;

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

    public function getStatus(): EmailStatus
    {
        return $this->status;
    }

    public function setStatus(EmailStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getBounceType(): ?EmailBounceType
    {
        return $this->bounceType;
    }

    public function setBounceType(?EmailBounceType $bounceType): static
    {
        $this->bounceType = $bounceType;

        return $this;
    }

    public function getBounceMessage(): ?string
    {
        return $this->bounceMessage;
    }

    public function setBounceMessage(?string $bounceMessage): static
    {
        $this->bounceMessage = $bounceMessage;

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

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;

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

    public function getBouncedAt(): ?\DateTimeImmutable
    {
        return $this->bouncedAt;
    }

    public function setBouncedAt(?\DateTimeImmutable $bouncedAt): static
    {
        $this->bouncedAt = $bouncedAt;

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

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Mark as sent.
     */
    public function markSent(string $messageId): static
    {
        $this->status = EmailStatus::SENT;
        $this->messageId = $messageId;
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark as delivered.
     */
    public function markDelivered(): static
    {
        $this->status = EmailStatus::DELIVERED;
        $this->deliveredAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark as opened.
     */
    public function markOpened(): static
    {
        if ($this->openedAt === null) {
            $this->openedAt = new \DateTimeImmutable();
        }

        if ($this->status === EmailStatus::SENT || $this->status === EmailStatus::DELIVERED) {
            $this->status = EmailStatus::OPENED;
        }

        return $this;
    }

    /**
     * Mark as clicked.
     */
    public function markClicked(): static
    {
        if ($this->clickedAt === null) {
            $this->clickedAt = new \DateTimeImmutable();
        }

        // Auto-track open if not already tracked
        if ($this->openedAt === null) {
            $this->openedAt = new \DateTimeImmutable();
        }

        if (in_array($this->status, [EmailStatus::SENT, EmailStatus::DELIVERED, EmailStatus::OPENED], true)) {
            $this->status = EmailStatus::CLICKED;
        }

        return $this;
    }

    /**
     * Mark as bounced.
     */
    public function markBounced(EmailBounceType $type, ?string $message = null): static
    {
        $this->status = EmailStatus::BOUNCED;
        $this->bounceType = $type;
        $this->bounceMessage = $message;
        $this->bouncedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark as complained (spam).
     */
    public function markComplained(?string $message = null): static
    {
        $this->status = EmailStatus::COMPLAINED;
        $this->bounceType = EmailBounceType::COMPLAINT;
        $this->bounceMessage = $message;
        $this->bouncedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): static
    {
        $this->status = EmailStatus::FAILED;
        $this->bounceMessage = $error;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->toEmail, $this->subject);
    }
}
