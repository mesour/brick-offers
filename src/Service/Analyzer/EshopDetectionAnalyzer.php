<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class EshopDetectionAnalyzer extends AbstractLeadAnalyzer
{
    private const CONFIDENCE_THRESHOLD = 3;

    private const PLATFORM_PATTERNS = [
        'woocommerce' => [
            'meta' => '/woocommerce/i',
            'class' => '/woocommerce|wc-|add_to_cart/i',
            'script' => '/wc-add-to-cart|woocommerce/i',
        ],
        'shopify' => [
            'meta' => '/shopify/i',
            'script' => '/cdn\.shopify\.com|shopify\.com/i',
            'class' => '/shopify-|product-form/i',
        ],
        'prestashop' => [
            'meta' => '/prestashop/i',
            'class' => '/prestashop|blockcart/i',
            'script' => '/prestashop/i',
        ],
        'magento' => [
            'meta' => '/magento/i',
            'class' => '/magento-|mage-|catalog-product/i',
            'script' => '/mage\/|magento/i',
        ],
        'opencart' => [
            'meta' => '/opencart/i',
            'class' => '/opencart/i',
            'script' => '/opencart/i',
        ],
        'shoptet' => [
            'meta' => '/shoptet/i',
            'class' => '/shoptet/i',
            'script' => '/shoptet\.cz/i',
        ],
        'upgates' => [
            'script' => '/upgates\.cz/i',
        ],
    ];

    private const CART_PATTERNS = [
        '/\b(košík|cart|basket|warenkorb|panier)\b/i',
        '/add[_-]?to[_-]?cart/i',
        '/do[_-]?košíku/i',
        '/přidat[_-]?do[_-]?košíku/i',
        '/buy[_-]?now/i',
        '/checkout/i',
        '/pokladna/i',
    ];

    private const PRICE_PATTERNS = [
        '/\d+[\s,.]?\d*\s*(Kč|CZK|EUR|€|\$|USD|PLN|zł)/i',
        '/(cena|price|preis|prix)[\s:]*\d/i',
        '/class=["\'][^"\']*price[^"\']*["\']/i',
        '/itemprop=["\']price["\']/i',
    ];

    private const SCHEMA_PATTERNS = [
        '/"@type"\s*:\s*"Product"/i',
        '/itemtype=["\']https?:\/\/schema\.org\/Product["\']/i',
        '/"@type"\s*:\s*"Offer"/i',
        '/itemtype=["\']https?:\/\/schema\.org\/Offer["\']/i',
    ];

    private const ECOMMERCE_INDICATORS = [
        '/product-detail|product-page|product-view/i',
        '/category-products|product-list|product-grid/i',
        '/shopping-cart|mini-cart|cart-icon/i',
        '/payment-methods|payment-icons/i',
        '/shipping-info|delivery-info/i',
        '/(visa|mastercard|paypal|gopay|comgate)/i',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::ESHOP_DETECTION;
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null || $result['content'] === null) {
            return AnalyzerResult::failure(
                $this->getCategory(),
                $result['error'] ?? 'Failed to fetch content'
            );
        }

        $content = $result['content'];
        $signals = $this->detectEshopSignals($content);
        $isEshop = $signals['totalScore'] >= self::CONFIDENCE_THRESHOLD;

        $rawData = [
            'url' => $url,
            'isEshop' => $isEshop,
            'confidence' => $this->calculateConfidence($signals['totalScore']),
            'totalScore' => $signals['totalScore'],
            'signals' => $signals,
        ];

        $this->logger->info('E-shop detection completed', [
            'url' => $url,
            'isEshop' => $isEshop,
            'confidence' => $rawData['confidence'],
            'score' => $signals['totalScore'],
        ]);

        return AnalyzerResult::success($this->getCategory(), [], $rawData);
    }

    /**
     * @return array{totalScore: int, platform: ?string, platformScore: int, cartSignals: array<string>, priceSignals: int, schemaSignals: int, ecommerceIndicators: int}
     */
    private function detectEshopSignals(string $content): array
    {
        $signals = [
            'totalScore' => 0,
            'platform' => null,
            'platformScore' => 0,
            'cartSignals' => [],
            'priceSignals' => 0,
            'schemaSignals' => 0,
            'ecommerceIndicators' => 0,
        ];

        $platformResult = $this->detectPlatform($content);
        if ($platformResult !== null) {
            $signals['platform'] = $platformResult;
            $signals['platformScore'] = 5;
            $signals['totalScore'] += 5;
        }

        foreach (self::CART_PATTERNS as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $signals['cartSignals'][] = $matches[0];
                $signals['totalScore'] += 2;
            }
        }
        $signals['cartSignals'] = array_unique(array_slice($signals['cartSignals'], 0, 5));

        foreach (self::PRICE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                $signals['priceSignals']++;
                $signals['totalScore'] += 1;
            }
        }

        foreach (self::SCHEMA_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                $signals['schemaSignals']++;
                $signals['totalScore'] += 3;
            }
        }

        foreach (self::ECOMMERCE_INDICATORS as $pattern) {
            if (preg_match($pattern, $content)) {
                $signals['ecommerceIndicators']++;
                $signals['totalScore'] += 1;
            }
        }

        return $signals;
    }

    private function detectPlatform(string $content): ?string
    {
        foreach (self::PLATFORM_PATTERNS as $platform => $patterns) {
            $matchCount = 0;

            if (isset($patterns['meta']) && preg_match($patterns['meta'], $content)) {
                $matchCount += 2;
            }

            if (isset($patterns['class']) && preg_match($patterns['class'], $content)) {
                $matchCount++;
            }

            if (isset($patterns['script']) && preg_match($patterns['script'], $content)) {
                $matchCount++;
            }

            if ($matchCount >= 2) {
                return $platform;
            }
        }

        return null;
    }

    private function calculateConfidence(int $score): string
    {
        if ($score >= 10) {
            return 'high';
        }

        if ($score >= self::CONFIDENCE_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }
}
