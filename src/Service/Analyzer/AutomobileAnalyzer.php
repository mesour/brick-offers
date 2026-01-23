<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for automobile dealerships.
 * Skeleton implementation - checks for: vehicle inventory, financing, test drive booking.
 */
class AutomobileAnalyzer extends AbstractLeadAnalyzer
{
    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_AUTOMOBILE;
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
        return [Industry::AUTOMOBILE];
    }

    public function getDescription(): string
    {
        return 'Kontroluje nabídku vozidel, financování, rezervaci zkušební jízdy.';
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

        // Check for vehicle inventory
        $inventoryResult = $this->checkInventory($content);
        $rawData['checks']['inventory'] = $inventoryResult['data'];
        array_push($issues, ...$inventoryResult['issues']);

        // Check for financing info
        $financingResult = $this->checkFinancing($content);
        $rawData['checks']['financing'] = $financingResult['data'];
        array_push($issues, ...$financingResult['issues']);

        // Check for test drive booking
        $testDriveResult = $this->checkTestDrive($content);
        $rawData['checks']['testDrive'] = $testDriveResult['data'];
        array_push($issues, ...$testDriveResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for vehicle inventory.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkInventory(string $content): array
    {
        $issues = [];
        $data = [
            'hasInventory' => false,
            'hasVehicleDetails' => false,
        ];

        // Check for vehicle/inventory mentions
        $data['hasInventory'] = (bool) preg_match('/(?:vozidla|automobil|auto|car|vehicle|nabídka\s*voz|skladem|inventory)/iu', $content);

        // Check for vehicle details (brand, model, year, km)
        $hasBrand = (bool) preg_match('/(?:škoda|volkswagen|bmw|audi|mercedes|toyota|hyundai|ford|peugeot|renault)/iu', $content);
        $hasKm = (bool) preg_match('/(?:\d+\s*km|kilometr|mileage)/iu', $content);
        $hasYear = (bool) preg_match('/(?:rok\s*výroby|year|20[12]\d)/iu', $content);

        $data['hasVehicleDetails'] = $hasBrand || $hasKm || $hasYear;

        if (!$data['hasInventory']) {
            $issues[] = $this->createIssue('automobile_no_inventory', 'Nenalezena nabídka vozidel');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for financing information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkFinancing(string $content): array
    {
        $issues = [];
        $data = [
            'hasFinancing' => false,
            'hasLeasing' => false,
            'hasCalculator' => false,
        ];

        // Check for financing mentions
        $data['hasFinancing'] = (bool) preg_match('/(?:financování|financing|úvěr|credit|splátky|monthly\s*payment)/iu', $content);

        // Check for leasing
        $data['hasLeasing'] = (bool) preg_match('/(?:leasing|operativní\s*leasing)/iu', $content);

        // Check for calculator
        $data['hasCalculator'] = (bool) preg_match('/(?:kalkulačka|calculator|spočítat\s*splátky)/iu', $content);

        if (!$data['hasFinancing'] && !$data['hasLeasing']) {
            $issues[] = $this->createIssue('automobile_no_financing', 'Chybí informace o financování nebo leasingu');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for test drive booking.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTestDrive(string $content): array
    {
        $issues = [];
        $data = [
            'hasTestDrive' => false,
            'hasOnlineBooking' => false,
        ];

        // Check for test drive mentions
        $data['hasTestDrive'] = (bool) preg_match('/(?:testovací\s*jízda|zkušební\s*jízda|test\s*drive|vyzkoušet)/iu', $content);

        // Check for online booking form
        $data['hasOnlineBooking'] = (bool) preg_match('/(?:objednat\s*(?:zkušební|testovací)|book\s*(?:a\s*)?test|rezervovat\s*jízdu)/iu', $content);

        if (!$data['hasTestDrive'] && !$data['hasOnlineBooking']) {
            $issues[] = $this->createIssue('automobile_no_test_drive', 'Chybí možnost objednání testovací jízdy');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
