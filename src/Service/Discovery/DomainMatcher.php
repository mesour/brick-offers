<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Service for matching domains against wildcard patterns.
 *
 * Uses PHP's fnmatch() function for pattern matching.
 * Patterns support:
 * - "example.com" - exact match
 * - "*.example.com" - any subdomain
 * - "example.*" - any TLD
 * - "*example*" - contains
 */
class DomainMatcher
{
    /**
     * Check if domain matches pattern using fnmatch().
     * Tests both the original domain and without www. prefix.
     */
    public function matches(string $domain, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));

        if ($pattern === '') {
            return false;
        }

        $normalizedDomain = $this->normalizeDomain($domain);
        $originalDomain = strtolower(trim($domain));

        // Check both normalized (without www) and original domain
        if (fnmatch($pattern, $normalizedDomain, FNM_CASEFOLD)) {
            return true;
        }

        // If domain had www., also check original with www.
        if ($originalDomain !== $normalizedDomain) {
            return fnmatch($pattern, $originalDomain, FNM_CASEFOLD);
        }

        return false;
    }

    /**
     * Check if domain matches any of the given patterns.
     *
     * @param array<string> $patterns
     */
    public function isExcluded(string $domain, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($domain, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter an array of results, removing those matching excluded patterns.
     *
     * @template T
     *
     * @param array<T>      $results
     * @param array<string> $patterns
     * @param callable(T): string $getDomainCallback Callback to extract domain from result
     *
     * @return array<T>
     */
    public function filterExcluded(array $results, array $patterns, callable $getDomainCallback): array
    {
        if (empty($patterns)) {
            return $results;
        }

        return array_filter(
            $results,
            fn ($result) => !$this->isExcluded($getDomainCallback($result), $patterns)
        );
    }

    /**
     * Normalize domain for matching.
     * - Converts to lowercase
     * - Removes www. prefix
     */
    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // Strip www. prefix for matching
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }
}
