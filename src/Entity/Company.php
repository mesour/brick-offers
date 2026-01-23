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
use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'companies')]
#[ORM\UniqueConstraint(name: 'companies_ico_unique', columns: ['ico'])]
#[ORM\Index(name: 'companies_name_idx', columns: ['name'])]
#[ORM\Index(name: 'companies_business_status_idx', columns: ['business_status'])]
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
    'ico' => 'exact',
    'dic' => 'exact',
    'name' => 'partial',
    'city' => 'partial',
    'businessStatus' => 'exact',
])]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    // === ARES DATA ===

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 8)]
    #[Assert\Regex(pattern: '/^\d{8}$/')]
    private string $ico;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dic = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $legalForm = null;

    // === ADRESA ===

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cityPart = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    // === STATUS ===

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $businessStatus = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aresData = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $aresUpdatedAt = null;

    // === VZTAHY ===

    /** @var Collection<int, Lead> */
    #[ORM\OneToMany(targetEntity: Lead::class, mappedBy: 'company')]
    private Collection $leads;

    // === TIMESTAMPS ===

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->leads = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getIco(): string
    {
        return $this->ico;
    }

    public function setIco(string $ico): static
    {
        $this->ico = $ico;

        return $this;
    }

    public function getDic(): ?string
    {
        return $this->dic;
    }

    public function setDic(?string $dic): static
    {
        $this->dic = $dic;

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

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function setLegalForm(?string $legalForm): static
    {
        $this->legalForm = $legalForm;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCityPart(): ?string
    {
        return $this->cityPart;
    }

    public function setCityPart(?string $cityPart): static
    {
        $this->cityPart = $cityPart;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getBusinessStatus(): ?string
    {
        return $this->businessStatus;
    }

    public function setBusinessStatus(?string $businessStatus): static
    {
        $this->businessStatus = $businessStatus;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAresData(): ?array
    {
        return $this->aresData;
    }

    /** @param array<string, mixed>|null $aresData */
    public function setAresData(?array $aresData): static
    {
        $this->aresData = $aresData;

        return $this;
    }

    public function getAresUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->aresUpdatedAt;
    }

    public function setAresUpdatedAt(?\DateTimeImmutable $aresUpdatedAt): static
    {
        $this->aresUpdatedAt = $aresUpdatedAt;

        return $this;
    }

    /** @return Collection<int, Lead> */
    public function getLeads(): Collection
    {
        return $this->leads;
    }

    public function addLead(Lead $lead): static
    {
        if (!$this->leads->contains($lead)) {
            $this->leads->add($lead);
            $lead->setCompany($this);
        }

        return $this;
    }

    public function removeLead(Lead $lead): static
    {
        if ($this->leads->removeElement($lead)) {
            if ($lead->getCompany() === $this) {
                $lead->setCompany(null);
            }
        }

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
     * Check if ARES data needs refresh (older than 30 days).
     */
    public function needsAresRefresh(): bool
    {
        if ($this->aresUpdatedAt === null) {
            return true;
        }

        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');

        return $this->aresUpdatedAt < $thirtyDaysAgo;
    }

    /**
     * Get full address as a single string.
     */
    public function getFullAddress(): ?string
    {
        $parts = [];

        if ($this->street !== null) {
            $parts[] = $this->street;
        }

        if ($this->cityPart !== null && $this->city !== null && $this->cityPart !== $this->city) {
            $parts[] = $this->cityPart;
        }

        if ($this->city !== null) {
            $cityWithPostal = $this->postalCode !== null
                ? sprintf('%s %s', $this->postalCode, $this->city)
                : $this->city;
            $parts[] = $cityWithPostal;
        }

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    public function __toString(): string
    {
        return $this->name ?? $this->ico;
    }
}
