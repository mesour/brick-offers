<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Enum\EmailBounceType;
use App\Repository\EmailBlacklistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Email blacklist entry.
 *
 * Dual-purpose: global (user_id = NULL) for hard bounces,
 * per-user (user_id set) for unsubscribes.
 */
#[ORM\Entity(repositoryClass: EmailBlacklistRepository::class)]
#[ORM\Table(name: 'email_blacklist')]
#[ORM\UniqueConstraint(name: 'email_blacklist_unique', columns: ['email', 'user_id'])]
#[ORM\Index(name: 'email_blacklist_email_idx', columns: ['email'])]
#[ORM\Index(name: 'email_blacklist_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'email_blacklist_type_idx', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Delete(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'email' => 'exact',
    'user.id' => 'exact',
    'user.code' => 'exact',
    'type' => 'exact',
])]
class EmailBlacklist
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * Blacklisted email address.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /**
     * User for per-user blacklist. NULL = global blacklist.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Type of blacklist entry.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: EmailBounceType::class)]
    private EmailBounceType $type;

    /**
     * Reason for blacklisting.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    /**
     * Source email log that caused this blacklist entry.
     */
    #[ORM\ManyToOne(targetEntity: EmailLog::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailLog $sourceEmailLog = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);

        return $this;
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

    public function getType(): EmailBounceType
    {
        return $this->type;
    }

    public function setType(EmailBounceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getSourceEmailLog(): ?EmailLog
    {
        return $this->sourceEmailLog;
    }

    public function setSourceEmailLog(?EmailLog $sourceEmailLog): static
    {
        $this->sourceEmailLog = $sourceEmailLog;

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
     * Check if this is a global blacklist entry.
     */
    public function isGlobal(): bool
    {
        return $this->user === null;
    }
}
