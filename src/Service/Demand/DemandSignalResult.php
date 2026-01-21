<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;

/**
 * Result from a demand signal source - represents a discovered RFP, tender, or job posting.
 */
readonly class DemandSignalResult
{
    public function __construct(
        public DemandSignalSource $source,
        public DemandSignalType $type,
        public string $externalId,
        public string $title,
        public ?string $description = null,
        public ?string $companyName = null,
        public ?string $ico = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $contactPerson = null,
        public ?float $value = null,
        public ?float $valueMax = null,
        public string $currency = 'CZK',
        public ?Industry $industry = null,
        public ?string $location = null,
        public ?string $region = null,
        public ?\DateTimeImmutable $deadline = null,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?string $sourceUrl = null,
        public array $rawData = [],
    ) {}

    /**
     * Check if the signal has deadline that hasn't passed.
     */
    public function isActive(): bool
    {
        if ($this->deadline === null) {
            return true;
        }

        return $this->deadline > new \DateTimeImmutable();
    }

    /**
     * Check if this is a high-value signal (above threshold).
     */
    public function isHighValue(float $threshold = 100000): bool
    {
        return $this->value !== null && $this->value >= $threshold;
    }
}
