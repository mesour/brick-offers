<?php

declare(strict_types=1);

namespace App\Service\Discovery;

readonly class DiscoveryResult
{
    public string $domain;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $url,
        public array $metadata = [],
    ) {
        $this->domain = $this->extractDomain($url);
    }

    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? $url;

        // Remove www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strtolower($host);
    }
}
