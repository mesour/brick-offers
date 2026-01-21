<?php

declare(strict_types=1);

namespace App\Service\Extractor;

readonly class PageData
{
    /**
     * @param array<string> $emails
     * @param array<string> $phones
     * @param array<string> $technologies
     * @param array<string, string> $socialMedia
     */
    public function __construct(
        public array $emails = [],
        public array $phones = [],
        public ?string $ico = null,
        public ?string $cms = null,
        public array $technologies = [],
        public array $socialMedia = [],
        public ?string $companyName = null,
        public ?string $address = null,
    ) {}

    /**
     * Get the primary (best) email address.
     */
    public function getPrimaryEmail(): ?string
    {
        return $this->emails[0] ?? null;
    }

    /**
     * Get the primary (best) phone number.
     */
    public function getPrimaryPhone(): ?string
    {
        return $this->phones[0] ?? null;
    }

    /**
     * Check if any contact data was extracted.
     */
    public function hasContactData(): bool
    {
        return !empty($this->emails)
            || !empty($this->phones)
            || $this->ico !== null
            || $this->companyName !== null;
    }

    /**
     * Check if any technology data was detected.
     */
    public function hasTechnologyData(): bool
    {
        return $this->cms !== null || !empty($this->technologies);
    }

    /**
     * Convert to metadata array for Lead entity.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $metadata = [];

        if (!empty($this->emails)) {
            $metadata['extracted_emails'] = $this->emails;
        }

        if (!empty($this->phones)) {
            $metadata['extracted_phones'] = $this->phones;
        }

        if ($this->ico !== null) {
            $metadata['extracted_ico'] = $this->ico;
        }

        if ($this->cms !== null) {
            $metadata['detected_cms'] = $this->cms;
        }

        if (!empty($this->technologies)) {
            $metadata['detected_technologies'] = $this->technologies;
        }

        if (!empty($this->socialMedia)) {
            $metadata['social_media'] = $this->socialMedia;
        }

        if ($this->companyName !== null) {
            $metadata['extracted_company_name'] = $this->companyName;
        }

        if ($this->address !== null) {
            $metadata['extracted_address'] = $this->address;
        }

        return $metadata;
    }
}
