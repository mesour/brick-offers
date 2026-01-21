<?php

declare(strict_types=1);

namespace App\Service\Ares;

readonly class AresData
{
    /**
     * @param array<string, mixed> $rawData
     */
    public function __construct(
        public string $ico,
        public string $name,
        public ?string $dic = null,
        public ?string $legalForm = null,
        public ?string $street = null,
        public ?string $city = null,
        public ?string $cityPart = null,
        public ?string $postalCode = null,
        public ?string $businessStatus = null,
        public array $rawData = [],
    ) {}

    /**
     * Create AresData from ARES API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromApiResponse(array $data): self
    {
        $ico = (string) ($data['ico'] ?? '');
        $name = $data['obchodniJmeno'] ?? $data['nazev'] ?? '';

        // Extract DIČ
        $dic = $data['dic'] ?? null;

        // Extract legal form
        $legalForm = null;
        if (isset($data['pravniForma'])) {
            $legalForm = $data['pravniForma']['nazev'] ?? $data['pravniForma']['kod'] ?? null;
        }

        // Extract address
        $street = null;
        $city = null;
        $cityPart = null;
        $postalCode = null;

        if (isset($data['sidlo'])) {
            $sidlo = $data['sidlo'];

            // Build street address
            $streetParts = [];
            if (!empty($sidlo['nazevUlice'])) {
                $streetParts[] = $sidlo['nazevUlice'];
            }
            if (!empty($sidlo['cisloDomovni'])) {
                $streetParts[] = $sidlo['cisloDomovni'];
                if (!empty($sidlo['cisloOrientacni'])) {
                    $streetParts[count($streetParts) - 1] .= '/' . $sidlo['cisloOrientacni'];
                }
            }
            $street = !empty($streetParts) ? implode(' ', $streetParts) : null;

            $city = $sidlo['nazevObce'] ?? null;
            $cityPart = $sidlo['nazevCastiObce'] ?? $sidlo['nazevMestskeCastiObvodu'] ?? null;
            $postalCode = isset($sidlo['psc']) ? (string) $sidlo['psc'] : null;
        }

        // Extract business status
        $businessStatus = null;
        if (isset($data['stavSubjektu'])) {
            $businessStatus = match ($data['stavSubjektu']) {
                'AKTIVNI' => 'Aktivní',
                'ZANIKLÝ', 'ZANIKLY' => 'Zaniklý',
                'NEAKTIVNI' => 'Neaktivní',
                'PRERUSENA_CINNOST' => 'Přerušená činnost',
                default => $data['stavSubjektu'],
            };
        }

        return new self(
            ico: $ico,
            name: $name,
            dic: $dic,
            legalForm: $legalForm,
            street: $street,
            city: $city,
            cityPart: $cityPart,
            postalCode: $postalCode,
            businessStatus: $businessStatus,
            rawData: $data,
        );
    }

    /**
     * Check if the business is active.
     */
    public function isActive(): bool
    {
        return $this->businessStatus === 'Aktivní' || $this->businessStatus === 'AKTIVNI';
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
}
