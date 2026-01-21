<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for restaurant websites.
 * Skeleton implementation - checks for: menu, online reservation, PDF-only menu.
 */
class RestaurantAnalyzer extends AbstractLeadAnalyzer
{
    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_RESTAURANT;
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
        return [Industry::RESTAURANT];
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

        // Check for menu
        $menuResult = $this->checkMenu($content);
        $rawData['checks']['menu'] = $menuResult['data'];
        array_push($issues, ...$menuResult['issues']);

        // Check for reservation
        $reservationResult = $this->checkReservation($content);
        $rawData['checks']['reservation'] = $reservationResult['data'];
        array_push($issues, ...$reservationResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for menu presence and format.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkMenu(string $content): array
    {
        $issues = [];
        $data = [
            'hasMenu' => false,
            'hasHtmlMenu' => false,
            'hasPdfMenu' => false,
            'hasMenuItems' => false,
        ];

        // Check for menu mentions
        $data['hasMenu'] = (bool) preg_match('/(?:jídelní\s*lístek|menu|nabídka\s*jídel|speciály|denní\s*menu)/iu', $content);

        // Check for PDF menu (common but not ideal)
        $data['hasPdfMenu'] = (bool) preg_match('/(?:href=["\'][^"\']*\.pdf["\'].*menu|menu.*\.pdf|stáhnout\s*menu)/iu', $content);

        // Check for HTML menu items (prices in Kč, dish names)
        $hasPrices = (bool) preg_match('/\d+\s*(?:,-|Kč|kč|CZK)/u', $content);
        $hasDishPatterns = (bool) preg_match('/(?:polévka|soup|předkrm|starter|hlavní\s*jídlo|main|dezert|dessert)/iu', $content);

        $data['hasHtmlMenu'] = $hasPrices && $hasDishPatterns;
        $data['hasMenuItems'] = $hasPrices || $hasDishPatterns;

        if (!$data['hasMenu'] && !$data['hasPdfMenu'] && !$data['hasHtmlMenu']) {
            $issues[] = $this->createIssue('restaurant_no_menu', 'Nenalezen jídelní lístek');
        } elseif ($data['hasPdfMenu'] && !$data['hasHtmlMenu']) {
            $issues[] = $this->createIssue('restaurant_menu_pdf_only', 'Menu je dostupné pouze jako PDF ke stažení');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for online reservation.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkReservation(string $content): array
    {
        $issues = [];
        $data = [
            'hasReservation' => false,
            'hasOnlineBooking' => false,
            'hasReservationForm' => false,
        ];

        // Check for reservation mentions
        $data['hasReservation'] = (bool) preg_match('/(?:rezervace|reservation|book\s*(?:a\s*)?table|objednat\s*stůl)/iu', $content);

        // Check for online booking systems (Restu, TheFork, etc.)
        $data['hasOnlineBooking'] = (bool) preg_match('/(?:restu|thefork|opentable|bookatable|online\s*rezervace)/iu', $content);

        // Check for reservation form
        $data['hasReservationForm'] = (bool) preg_match('/<form[^>]*(?:rezervace|reservation|booking)/iu', $content);

        // Also check for date/time inputs which suggest booking
        if (!$data['hasReservationForm']) {
            $hasDateInput = (bool) preg_match('/(?:type=["\']date["\']|name=["\'](?:datum|date)["\'])/i', $content);
            $hasTimeInput = (bool) preg_match('/(?:type=["\']time["\']|name=["\'](?:cas|time)["\'])/i', $content);
            $data['hasReservationForm'] = $hasDateInput && $hasTimeInput;
        }

        if (!$data['hasReservation'] && !$data['hasOnlineBooking'] && !$data['hasReservationForm']) {
            $issues[] = $this->createIssue('restaurant_no_reservation', 'Chybí možnost online rezervace');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
