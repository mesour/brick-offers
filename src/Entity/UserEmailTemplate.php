<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\Industry;
use App\Repository\UserEmailTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User-specific email template overrides.
 *
 * Can be based on a global EmailTemplate or created from scratch.
 * Supports AI personalization settings.
 */
#[ORM\Entity(repositoryClass: UserEmailTemplateRepository::class)]
#[ORM\Table(name: 'user_email_templates')]
#[ORM\UniqueConstraint(name: 'user_email_templates_user_name_unique', columns: ['user_id', 'name'])]
#[ORM\Index(name: 'user_email_templates_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'user_email_templates_user_industry_active_idx', columns: ['user_id', 'industry', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
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
    'user.id' => 'exact',
    'user.code' => 'exact',
    'name' => 'partial',
    'industry' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'aiPersonalizationEnabled'])]
class UserEmailTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * Owner of this template.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Base template this is derived from (optional).
     */
    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailTemplate $baseTemplate = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * Subject template with {{placeholder}} format.
     */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private string $subjectTemplate = '';

    /**
     * Body template with {{placeholder}} format (HTML).
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $bodyTemplate = '';

    /**
     * Target industry (optional).
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Whether AI personalization is enabled for this template.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $aiPersonalizationEnabled = true;

    /**
     * Custom AI prompt for personalization (optional).
     * If not set, uses default personalization prompt.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aiPersonalizationPrompt = null;

    /**
     * Documentation of available template variables.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $variables = [];

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

    public function getBaseTemplate(): ?EmailTemplate
    {
        return $this->baseTemplate;
    }

    public function setBaseTemplate(?EmailTemplate $baseTemplate): static
    {
        $this->baseTemplate = $baseTemplate;

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

    public function getSubjectTemplate(): string
    {
        return $this->subjectTemplate;
    }

    public function setSubjectTemplate(string $subjectTemplate): static
    {
        $this->subjectTemplate = $subjectTemplate;

        return $this;
    }

    public function getBodyTemplate(): string
    {
        return $this->bodyTemplate;
    }

    public function setBodyTemplate(string $bodyTemplate): static
    {
        $this->bodyTemplate = $bodyTemplate;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isAiPersonalizationEnabled(): bool
    {
        return $this->aiPersonalizationEnabled;
    }

    public function setAiPersonalizationEnabled(bool $aiPersonalizationEnabled): static
    {
        $this->aiPersonalizationEnabled = $aiPersonalizationEnabled;

        return $this;
    }

    public function getAiPersonalizationPrompt(): ?string
    {
        return $this->aiPersonalizationPrompt;
    }

    public function setAiPersonalizationPrompt(?string $aiPersonalizationPrompt): static
    {
        $this->aiPersonalizationPrompt = $aiPersonalizationPrompt;

        return $this;
    }

    /** @return array<string, string> */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /** @param array<string, string> $variables */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;

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
     * Render subject with variables.
     *
     * @param array<string, string> $vars
     */
    public function renderSubject(array $vars): string
    {
        $subject = $this->subjectTemplate;
        foreach ($vars as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }

        return $subject;
    }

    /**
     * Render body with variables.
     *
     * @param array<string, string> $vars
     */
    public function renderBody(array $vars): string
    {
        $body = $this->bodyTemplate;
        foreach ($vars as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }

        return $body;
    }

    /**
     * Copy content from base template.
     */
    public function copyFromBaseTemplate(): static
    {
        if ($this->baseTemplate === null) {
            return $this;
        }

        $this->subjectTemplate = $this->baseTemplate->getSubjectTemplate();
        $this->bodyTemplate = $this->baseTemplate->getBodyTemplate();
        $this->variables = $this->baseTemplate->getVariables();

        if ($this->baseTemplate->getIndustry() !== null) {
            $this->industry = $this->baseTemplate->getIndustry();
        }

        return $this;
    }
}
