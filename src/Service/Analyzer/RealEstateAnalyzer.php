<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for real estate websites.
 * Skeleton implementation - checks for: property listings, search filters, agent contact.
 */
class RealEstateAnalyzer extends AbstractLeadAnalyzer
{
    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_REAL_ESTATE;
    }

    public function getPriority(): int
    {
        return 100;
    }

    /**
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return [Industry::REAL_ESTATE];
    }

    public function getDescription(): string
    {
        return 'Kontroluje nabídky nemovitostí, filtry vyhledávání, kontakty na makléře.';
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $issues = [];
        $rawData = [
            'url' => $url,
            'checks' => [],
        ];

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null) {
            return AnalyzerResult::failure($this->getCategory(), 'Failed to fetch URL: ' . $result['error']);
        }

        $content = $result['content'] ?? '';

        // Check for property listings
        $listingsResult = $this->checkListings($content);
        $rawData['checks']['listings'] = $listingsResult['data'];
        array_push($issues, ...$listingsResult['issues']);

        // Check for search/filters
        $searchResult = $this->checkSearchFilters($content);
        $rawData['checks']['search'] = $searchResult['data'];
        array_push($issues, ...$searchResult['issues']);

        // Check for agent contact
        $agentResult = $this->checkAgentContact($content);
        $rawData['checks']['agent'] = $agentResult['data'];
        array_push($issues, ...$agentResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for property listings.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkListings(string $content): array
    {
        $issues = [];
        $data = [
            'hasListings' => false,
            'hasPropertyTypes' => false,
        ];

        // Check for listings/offers
        $data['hasListings'] = (bool) preg_match('/(?:nabídka|nemovitost|byt|dům|house|apartment|property|listing|prodej|pronájem|rent|sale)/iu', $content);

        // Check for property type mentions
        $data['hasPropertyTypes'] = (bool) preg_match('/(?:\d+\s*(?:\+\s*)?(?:kk|1|2|3|4)\b|m²|m2|pokojový|bedroom)/iu', $content);

        if (!$data['hasListings']) {
            $issues[] = $this->createIssue('realestate_no_listings', 'Nenalezeny nabídky nemovitostí');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for search and filter functionality.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkSearchFilters(string $content): array
    {
        $issues = [];
        $data = [
            'hasSearch' => false,
            'hasFilters' => false,
        ];

        // Check for search functionality
        $data['hasSearch'] = (bool) preg_match('/(?:hledat|vyhled|search|filtr)/iu', $content);

        // Check for filter options (price, location, type)
        $hasLocationFilter = (bool) preg_match('/(?:lokalita|location|město|city|okres|region)/iu', $content);
        $hasPriceFilter = (bool) preg_match('/(?:cena\s*(?:od|do)|price\s*(?:from|to)|rozpočet|budget)/iu', $content);

        $data['hasFilters'] = $hasLocationFilter || $hasPriceFilter;

        if (!$data['hasSearch'] && !$data['hasFilters']) {
            $issues[] = $this->createIssue('realestate_no_search_filters', 'Chybí možnost vyhledávání a filtrování nemovitostí');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for agent contact information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkAgentContact(string $content): array
    {
        $issues = [];
        $data = [
            'hasAgentInfo' => false,
            'hasAgentContact' => false,
        ];

        // Check for agent/realtor mentions
        $data['hasAgentInfo'] = (bool) preg_match('/(?:makléř|realitní\s*agent|agent|realtor|broker)/iu', $content);

        // Check for contact with agent
        $data['hasAgentContact'] = (bool) preg_match('/(?:kontaktovat\s*makléře|contact\s*agent|napsat\s*makléři|sjednat\s*prohlídku)/iu', $content);

        $hasPhone = (bool) preg_match('/(?:\+420\s*)?(?:\d{3}\s*){3}/u', $content);

        if (!$data['hasAgentInfo'] && !$data['hasAgentContact'] && !$hasPhone) {
            $issues[] = $this->createIssue('realestate_no_contact_agent', 'Chybí kontakt na makléře');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
