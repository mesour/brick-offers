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
use App\Enum\Industry;
use App\Repository\EmailTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Email template with hierarchical resolution.
 *
 * Resolution order:
 * 1. User-specific template (user_id NOT NULL)
 * 2. Industry-specific global template (user_id NULL, industry NOT NULL)
 * 3. Default global template (user_id NULL, industry NULL)
 */
#[ORM\Entity(repositoryClass: EmailTemplateRepository::class)]
#[ORM\Table(name: 'email_templates')]
#[ORM\UniqueConstraint(name: 'email_templates_user_name_unique', columns: ['user_id', 'name'])]
#[ORM\Index(name: 'email_templates_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'email_templates_industry_idx', columns: ['industry'])]
#[ORM\Index(name: 'email_templates_is_default_idx', columns: ['is_default'])]
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
    'name' => 'partial',
    'industry' => 'exact',
    'isDefault' => 'exact',
])]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * Owner of this template. NULL = global template.
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'emailTemplates')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private string $subjectTemplate;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $bodyTemplate;

    /**
     * Industry this template is designed for. NULL = generic template.
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
    private ?Industry $industry = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isDefault = false;

    /**
     * Documentation of available placeholders.
     * Example: {"company_name": "Company name from ARES", "domain": "Website domain"}
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function isGlobal(): bool
    {
        return $this->user === null;
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

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

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
     * Render subject with given variables.
     *
     * @param array<string, string> $vars
     */
    public function renderSubject(array $vars): string
    {
        return $this->render($this->subjectTemplate, $vars);
    }

    /**
     * Render body with given variables.
     *
     * @param array<string, string> $vars
     */
    public function renderBody(array $vars): string
    {
        return $this->render($this->bodyTemplate, $vars);
    }

    /**
     * Simple placeholder replacement.
     *
     * @param array<string, string> $vars
     */
    private function render(string $template, array $vars): string
    {
        $placeholders = array_map(fn ($key) => '{{' . $key . '}}', array_keys($vars));

        return str_replace($placeholders, array_values($vars), $template);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
