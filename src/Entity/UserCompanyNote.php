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
use App\Enum\RelationshipStatus;
use App\Repository\UserCompanyNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * User-specific notes and metadata about a shared company.
 * Allows each user to maintain their own relationship status, notes, and tags for a company.
 */
#[ORM\Entity(repositoryClass: UserCompanyNoteRepository::class)]
#[ORM\Table(name: 'user_company_notes')]
#[ORM\UniqueConstraint(name: 'user_company_notes_user_company_unique', columns: ['user_id', 'company_id'])]
#[ORM\Index(name: 'user_company_notes_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'user_company_notes_company_idx', columns: ['company_id'])]
#[ORM\Index(name: 'user_company_notes_status_idx', columns: ['relationship_status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    order: ['updatedAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'user.id' => 'exact',
    'company.id' => 'exact',
    'company.ico' => 'exact',
    'relationshipStatus' => 'exact',
])]
class UserCompanyNote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'companyNotes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * User-defined tags for categorization.
     *
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: RelationshipStatus::class)]
    private ?RelationshipStatus $relationshipStatus = null;

    /**
     * Custom fields for user-specific data.
     * Example: {"assigned_to": "John", "priority": "high", "last_call": "2024-01-15"}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $customFields = [];

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

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): static
    {
        $this->company = $company;

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

    /** @return array<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param array<string> $tags */
    public function setTags(array $tags): static
    {
        $this->tags = array_values(array_unique($tags));

        return $this;
    }

    public function addTag(string $tag): static
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function removeTag(string $tag): static
    {
        $this->tags = array_values(array_filter($this->tags, fn ($t) => $t !== $tag));

        return $this;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function getRelationshipStatus(): ?RelationshipStatus
    {
        return $this->relationshipStatus;
    }

    public function setRelationshipStatus(?RelationshipStatus $relationshipStatus): static
    {
        $this->relationshipStatus = $relationshipStatus;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    /** @param array<string, mixed> $customFields */
    public function setCustomFields(array $customFields): static
    {
        $this->customFields = $customFields;

        return $this;
    }

    public function getCustomField(string $key, mixed $default = null): mixed
    {
        return $this->customFields[$key] ?? $default;
    }

    public function setCustomField(string $key, mixed $value): static
    {
        $this->customFields[$key] = $value;

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
}
