<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\CrawlFrequency;
use App\Repository\MonitoredDomainRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a globally monitored domain for competitor tracking.
 * Managed by server admin via CLI. Users subscribe to domains via MonitoredDomainSubscription.
 */
#[ORM\Entity(repositoryClass: MonitoredDomainRepository::class)]
#[ORM\Table(name: 'monitored_domains')]
#[ORM\Index(name: 'monitored_domains_last_crawled_at_idx', columns: ['last_crawled_at'])]
#[ORM\Index(name: 'monitored_domains_crawl_frequency_idx', columns: ['crawl_frequency'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
    ],
    order: ['lastCrawledAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'domain' => 'partial',
    'crawlFrequency' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['lastCrawledAt'])]
class MonitoredDomain
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $domain;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Assert\Length(max: 500)]
    private string $url;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCrawledAt = null;

    #[ORM\Column(type: Types::STRING, length: 15, enumType: CrawlFrequency::class, options: ['default' => 'weekly'])]
    private CrawlFrequency $crawlFrequency = CrawlFrequency::WEEKLY;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Extra metadata about the monitored domain.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    /** @var Collection<int, MonitoredDomainSubscription> */
    #[ORM\OneToMany(targetEntity: MonitoredDomainSubscription::class, mappedBy: 'monitoredDomain', cascade: ['persist', 'remove'])]
    private Collection $subscriptions;

    /** @var Collection<int, CompetitorSnapshot> */
    #[ORM\OneToMany(targetEntity: CompetitorSnapshot::class, mappedBy: 'monitoredDomain', cascade: ['remove'])]
    private Collection $snapshots;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->subscriptions = new ArrayCollection();
        $this->snapshots = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = strtolower(trim($domain));

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getLastCrawledAt(): ?\DateTimeImmutable
    {
        return $this->lastCrawledAt;
    }

    public function setLastCrawledAt(?\DateTimeImmutable $lastCrawledAt): static
    {
        $this->lastCrawledAt = $lastCrawledAt;

        return $this;
    }

    public function markCrawled(): static
    {
        $this->lastCrawledAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCrawlFrequency(): CrawlFrequency
    {
        return $this->crawlFrequency;
    }

    public function setCrawlFrequency(CrawlFrequency $crawlFrequency): static
    {
        $this->crawlFrequency = $crawlFrequency;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

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

    /** @return Collection<int, MonitoredDomainSubscription> */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    /** @return Collection<int, CompetitorSnapshot> */
    public function getSnapshots(): Collection
    {
        return $this->snapshots;
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
     * Check if this domain should be crawled based on frequency and last crawl time.
     */
    public function shouldCrawl(): bool
    {
        if (!$this->active) {
            return false;
        }

        return $this->crawlFrequency->shouldCrawl($this->lastCrawledAt);
    }

    /**
     * Get the number of active subscribers.
     */
    public function getSubscriberCount(): int
    {
        return $this->subscriptions->count();
    }

    public function __toString(): string
    {
        return $this->domain;
    }
}
