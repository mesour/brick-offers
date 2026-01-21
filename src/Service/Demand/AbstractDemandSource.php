<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for demand signal sources.
 */
abstract class AbstractDemandSource implements DemandSignalSourceInterface
{
    protected int $requestDelayMs = 500;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply rate limiting between requests.
     */
    protected function rateLimit(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }

    /**
     * Set the delay between requests in milliseconds.
     */
    public function setRequestDelay(int $delayMs): void
    {
        $this->requestDelayMs = max(0, $delayMs);
    }

    /**
     * Parse Czech date string to DateTimeImmutable.
     */
    protected function parseCzechDate(string $date): ?\DateTimeImmutable
    {
        $date = trim($date);

        // Try common formats
        $formats = [
            'd.m.Y',
            'd.m.Y H:i',
            'd.m.Y H:i:s',
            'j.n.Y',
            'j.n.Y H:i',
            'Y-m-d',
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Parse price string to float.
     */
    protected function parsePrice(string $price): ?float
    {
        // Remove currency symbols and whitespace
        $price = preg_replace('/[^0-9,.\-]/', '', $price);
        if (empty($price)) {
            return null;
        }

        // Handle Czech format (1 234 567,89)
        $price = str_replace(' ', '', $price);
        $price = str_replace(',', '.', $price);

        $value = (float) $price;

        return $value > 0 ? $value : null;
    }

    /**
     * Detect industry from text.
     */
    protected function detectIndustry(string $text): ?Industry
    {
        $text = mb_strtolower($text);

        $patterns = [
            Industry::WEBDESIGN => ['web', 'website', 'webov', 'internetov', 'portál', 'aplikac', 'software'],
            Industry::ESHOP => ['e-shop', 'eshop', 'obchod', 'prodej', 'nákup', 'zboží'],
            Industry::REAL_ESTATE => ['nemovitost', 'reality', 'byt', 'dům', 'stavb'],
            Industry::AUTOMOBILE => ['auto', 'vozidl', 'doprav', 'servis vozid'],
            Industry::RESTAURANT => ['restaurac', 'gastronomie', 'jídl', 'kuchyn', 'catering'],
            Industry::MEDICAL => ['zdravot', 'lékař', 'nemocnic', 'klinik', 'medicín'],
            Industry::LEGAL => ['práv', 'advokát', 'právník', 'notář', 'soud'],
            Industry::FINANCE => ['financ', 'účetn', 'daň', 'pojišt', 'bank', 'úvěr'],
            Industry::EDUCATION => ['vzdělá', 'škol', 'kurz', 'školení', 'výuk'],
        ];

        foreach ($patterns as $industry => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $industry;
                }
            }
        }

        return Industry::OTHER;
    }

    /**
     * Detect demand signal type from text and source context.
     */
    protected function detectSignalType(string $text, string $category = ''): DemandSignalType
    {
        $text = mb_strtolower($text . ' ' . $category);

        // Web-related
        if (preg_match('/web|www|internet|portál|stránk/', $text)) {
            return DemandSignalType::RFP_WEB;
        }

        // E-shop
        if (preg_match('/e-shop|eshop|obchod/', $text)) {
            return DemandSignalType::RFP_ESHOP;
        }

        // App/software
        if (preg_match('/aplikac|software|mobilní|app/', $text)) {
            return DemandSignalType::RFP_APP;
        }

        // Marketing
        if (preg_match('/marketing|reklam|seo|ppc|kampaň/', $text)) {
            return DemandSignalType::RFP_MARKETING;
        }

        // Design
        if (preg_match('/design|grafik|logo|vizuál/', $text)) {
            return DemandSignalType::RFP_DESIGN;
        }

        // IT
        if (preg_match('/it |informační|server|síť|cloud|bezpečnost/', $text)) {
            return DemandSignalType::RFP_IT;
        }

        return DemandSignalType::RFP_OTHER;
    }

    /**
     * Extract IČO from text.
     */
    protected function extractIco(string $text): ?string
    {
        if (preg_match('/IČ[O]?[\s:]*(\d{8})/i', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{8})\b/', $text, $matches)) {
            // Validate with modulo 11
            $ico = $matches[1];
            if ($this->validateIco($ico)) {
                return $ico;
            }
        }

        return null;
    }

    /**
     * Validate Czech IČO using modulo 11 algorithm.
     */
    protected function validateIco(string $ico): bool
    {
        if (strlen($ico) !== 8 || !ctype_digit($ico)) {
            return false;
        }

        $weights = [8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $ico[$i] * $weights[$i];
        }

        $remainder = $sum % 11;
        $checkDigit = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        return (int) $ico[7] === $checkDigit;
    }
}
