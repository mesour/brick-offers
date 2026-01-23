<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for e-commerce websites.
 * Checks for: product pages, cart, payment methods, shipping, trust signals.
 */
class EshopAnalyzer extends AbstractLeadAnalyzer
{
    // Payment method patterns (Czech + international)
    private const PAYMENT_PATTERNS = [
        'card' => '/(?:platební\s*karta|credit\s*card|debit\s*card|visa|mastercard|maestro|kartou)/iu',
        'bank_transfer' => '/(?:bankovní\s*převod|bank\s*transfer|převodem)/iu',
        'cash_on_delivery' => '/(?:dobírka|cash\s*on\s*delivery|cod\b)/iu',
        'paypal' => '/paypal/i',
        'gopay' => '/gopay/i',
        'comgate' => '/comgate/i',
        'stripe' => '/stripe/i',
        'apple_pay' => '/apple\s*pay/i',
        'google_pay' => '/google\s*pay/i',
    ];

    // Shipping patterns (Czech + international)
    private const SHIPPING_PATTERNS = [
        'ppl' => '/\bppl\b/i',
        'dpd' => '/\bdpd\b/i',
        'gls' => '/\bgls\b/i',
        'czech_post' => '/(?:česká\s*pošta|ceska\s*posta)/iu',
        'zasilkovna' => '/(?:zásilkovna|zasilkovna|packeta)/iu',
        'pickup' => '/(?:osobní\s*odběr|vyzvednutí|pickup)/iu',
        'delivery' => '/(?:doručení|doprava|shipping|delivery)/iu',
    ];

    // Trust signal patterns
    private const TRUST_PATTERNS = [
        'heureka' => '/heureka/i',
        'zbozi' => '/zbozi\.cz|zboží\.cz/iu',
        'cesky_produkt' => '/česk[ýá]\s*(?:produkt|výrob)/iu',
        'guarantee' => '/(?:záruka|garance|guarantee)/iu',
        'secure_payment' => '/(?:bezpečná\s*platba|secure\s*payment|ssl)/iu',
        'verified' => '/(?:ověřen[oý]|verified)/iu',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_ESHOP;
    }

    public function getPriority(): int
    {
        return 100; // Industry-specific analyzers run after universal ones
    }

    /**
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return [Industry::ESHOP];
    }

    public function getDescription(): string
    {
        return 'Kontroluje e-shop specifické prvky: produkty, košík, platby, doprava, trust signály.';
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

        // Check for product pages/schema
        $productResult = $this->checkProductPages($content);
        $rawData['checks']['products'] = $productResult['data'];
        array_push($issues, ...$productResult['issues']);

        // Check for cart
        $cartResult = $this->checkCart($content);
        $rawData['checks']['cart'] = $cartResult['data'];
        array_push($issues, ...$cartResult['issues']);

        // Check for payment methods
        $paymentResult = $this->checkPaymentMethods($content);
        $rawData['checks']['payment'] = $paymentResult['data'];
        array_push($issues, ...$paymentResult['issues']);

        // Check for shipping info
        $shippingResult = $this->checkShippingInfo($content);
        $rawData['checks']['shipping'] = $shippingResult['data'];
        array_push($issues, ...$shippingResult['issues']);

        // Check for trust signals
        $trustResult = $this->checkTrustSignals($content);
        $rawData['checks']['trust'] = $trustResult['data'];
        array_push($issues, ...$trustResult['issues']);

        // Check for search functionality
        $searchResult = $this->checkSearch($content);
        $rawData['checks']['search'] = $searchResult['data'];
        array_push($issues, ...$searchResult['issues']);

        // Check for contact info
        $contactResult = $this->checkContactInfo($content);
        $rawData['checks']['contact'] = $contactResult['data'];
        array_push($issues, ...$contactResult['issues']);

        // Check for return policy
        $returnResult = $this->checkReturnPolicy($content);
        $rawData['checks']['returns'] = $returnResult['data'];
        array_push($issues, ...$returnResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for product pages and schema markup.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkProductPages(string $content): array
    {
        $issues = [];
        $data = [
            'hasProductSchema' => false,
            'hasProductStructure' => false,
            'productSignals' => [],
        ];

        // Check for Product schema markup (JSON-LD or microdata)
        $hasJsonLdProduct = (bool) preg_match('/"@type"\s*:\s*"Product"/i', $content);
        $hasMicrodataProduct = (bool) preg_match('/itemtype=["\']https?:\/\/schema\.org\/Product["\']/', $content);
        $data['hasProductSchema'] = $hasJsonLdProduct || $hasMicrodataProduct;

        // Check for common e-shop product elements
        $productSignals = [];

        // Add to cart button
        if (preg_match('/(?:přidat\s*do\s*košíku|add\s*to\s*cart|koupit|buy\s*now)/iu', $content)) {
            $productSignals[] = 'add_to_cart_button';
        }

        // Price patterns (Kč, CZK, €, $)
        if (preg_match('/(?:\d+[\s,.]?\d*)\s*(?:Kč|CZK|€|\$|EUR)/iu', $content)) {
            $productSignals[] = 'price_display';
        }

        // Product images/gallery
        if (preg_match('/(?:product[_-]?image|gallery|carousel)/i', $content)) {
            $productSignals[] = 'product_images';
        }

        // Availability/stock
        if (preg_match('/(?:skladem|dostupn[ýáé]|in\s*stock|available)/iu', $content)) {
            $productSignals[] = 'availability';
        }

        $data['productSignals'] = $productSignals;
        $data['hasProductStructure'] = count($productSignals) >= 2;

        if (!$data['hasProductSchema'] && !$data['hasProductStructure']) {
            $issues[] = $this->createIssue('eshop_no_product_pages', 'Nebyly nalezeny produktové prvky ani schema markup');
        } elseif (!$data['hasProductSchema'] && $data['hasProductStructure']) {
            $issues[] = $this->createIssue('eshop_no_product_schema', 'Produkty nalezeny, ale chybí schema.org/Product markup');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for shopping cart functionality.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkCart(string $content): array
    {
        $issues = [];
        $data = [
            'hasCart' => false,
            'hasCartLink' => false,
            'hasCartIcon' => false,
            'hasItemCount' => false,
            'hasTotalPrice' => false,
        ];

        // Check for cart link/element
        $hasCartLink = (bool) preg_match('/(?:href=["\'][^"\']*(?:kosik|cart|basket)["\'])/iu', $content);
        $hasCartText = (bool) preg_match('/(?:košík|nákupní\s*košík|shopping\s*cart|cart\b)/iu', $content);
        $hasCartIcon = (bool) preg_match('/(?:cart[_-]?icon|shopping[_-]?cart|basket[_-]?icon|fa-shopping)/i', $content);

        $data['hasCartLink'] = $hasCartLink;
        $data['hasCartIcon'] = $hasCartIcon;
        $data['hasCart'] = $hasCartLink || $hasCartText || $hasCartIcon;

        // Check for cart item count display
        if (preg_match('/(?:cart[_-]?count|item[_-]?count|badge)/i', $content)) {
            $data['hasItemCount'] = true;
        }

        // Check for total price in cart preview
        if (preg_match('/(?:celkem|total|suma|subtotal)/iu', $content)) {
            $data['hasTotalPrice'] = true;
        }

        if (!$data['hasCart']) {
            $issues[] = $this->createIssue('eshop_no_cart', 'Nenalezen nákupní košík ani odkaz na něj');
        } elseif (!$data['hasItemCount'] && !$data['hasTotalPrice']) {
            $issues[] = $this->createIssue('eshop_cart_ux_issues', 'Košík nezobrazuje počet položek ani celkovou cenu');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for payment methods.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkPaymentMethods(string $content): array
    {
        $issues = [];
        $data = [
            'methods' => [],
            'methodCount' => 0,
            'hasPaymentSection' => false,
        ];

        // Check for payment section
        $data['hasPaymentSection'] = (bool) preg_match('/(?:způsob\s*platby|platební\s*metod|payment\s*method)/iu', $content);

        // Detect specific payment methods
        foreach (self::PAYMENT_PATTERNS as $method => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['methods'][] = $method;
            }
        }

        $data['methodCount'] = count($data['methods']);

        if ($data['methodCount'] === 0 && !$data['hasPaymentSection']) {
            $issues[] = $this->createIssue('eshop_no_payment_methods', 'Nenalezeny žádné informace o platebních metodách');
        } elseif ($data['methodCount'] <= 2) {
            $issues[] = $this->createIssue('eshop_limited_payment_methods', sprintf('Nalezeny pouze %d platební metody: %s', $data['methodCount'], implode(', ', $data['methods'])));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for shipping information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkShippingInfo(string $content): array
    {
        $issues = [];
        $data = [
            'carriers' => [],
            'hasShippingSection' => false,
            'hasFreeShippingInfo' => false,
        ];

        // Check for shipping section
        $data['hasShippingSection'] = (bool) preg_match('/(?:způsob\s*doprav|doprava\s*a\s*platba|shipping|delivery)/iu', $content);

        // Detect specific carriers
        foreach (self::SHIPPING_PATTERNS as $carrier => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['carriers'][] = $carrier;
            }
        }

        // Check for free shipping threshold
        if (preg_match('/(?:doprava\s*zdarma|free\s*shipping)/iu', $content)) {
            $data['hasFreeShippingInfo'] = true;
        }

        $hasShippingInfo = $data['hasShippingSection'] || count($data['carriers']) > 0;

        if (!$hasShippingInfo) {
            $issues[] = $this->createIssue('eshop_no_shipping_info', 'Nenalezeny informace o možnostech dopravy');
        } elseif (!$data['hasFreeShippingInfo']) {
            $issues[] = $this->createIssue('eshop_no_free_shipping_threshold', 'Nenalezena informace o dopravě zdarma');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for trust signals (SSL seal, reviews, certificates).
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTrustSignals(string $content): array
    {
        $issues = [];
        $data = [
            'signals' => [],
            'hasReviews' => false,
            'hasSSLSeal' => false,
            'hasTrustBadges' => false,
        ];

        // Detect trust signals
        foreach (self::TRUST_PATTERNS as $signal => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['signals'][] = $signal;
            }
        }

        // Check for reviews/ratings
        $data['hasReviews'] = (bool) preg_match('/(?:hodnocení|recenze|reviews?|rating|stars?|hvězd)/iu', $content);
        if ($data['hasReviews']) {
            $data['signals'][] = 'reviews';
        }

        // Check for SSL seal images
        $data['hasSSLSeal'] = (bool) preg_match('/(?:ssl[_-]?seal|secure[_-]?badge|thawte|comodo|digicert|lets[_-]?encrypt)/i', $content);
        if ($data['hasSSLSeal']) {
            $data['signals'][] = 'ssl_seal';
        }

        $data['hasTrustBadges'] = count($data['signals']) >= 2;

        // Check for Heureka/Zbozi specifically (common in Czech market)
        $hasMarketplaceBadge = in_array('heureka', $data['signals'], true) || in_array('zbozi', $data['signals'], true);

        if (!$data['hasSSLSeal']) {
            $issues[] = $this->createIssue('eshop_no_ssl_seal', 'Nenalezena bezpečnostní pečeť SSL');
        }

        if (!$data['hasReviews']) {
            $issues[] = $this->createIssue('eshop_no_reviews', 'Nenalezeny zákaznické recenze ani hodnocení');
        }

        if (!$hasMarketplaceBadge && count($data['signals']) < 3) {
            $issues[] = $this->createIssue('eshop_no_trust_badges', sprintf('Málo trust signálů (nalezeno: %s)', implode(', ', $data['signals']) ?: 'žádné'));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for search functionality.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkSearch(string $content): array
    {
        $issues = [];
        $data = [
            'hasSearch' => false,
            'hasSearchForm' => false,
            'hasSearchIcon' => false,
        ];

        // Check for search form
        $data['hasSearchForm'] = (bool) preg_match('/<form[^>]*(?:search|hledat|vyhled)/iu', $content);

        // Check for search input
        $hasSearchInput = (bool) preg_match('/(?:type=["\']search["\']|name=["\'](?:q|query|search|hledat)["\'])/i', $content);

        // Check for search icon/button
        $data['hasSearchIcon'] = (bool) preg_match('/(?:search[_-]?icon|fa-search|icon-search|lupa)/i', $content);

        // Check for search text
        $hasSearchText = (bool) preg_match('/(?:hledat|vyhled|search)/iu', $content);

        $data['hasSearch'] = $data['hasSearchForm'] || $hasSearchInput || ($data['hasSearchIcon'] && $hasSearchText);

        if (!$data['hasSearch']) {
            $issues[] = $this->createIssue('eshop_no_search', 'Nenalezena funkce vyhledávání produktů');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for contact information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkContactInfo(string $content): array
    {
        $issues = [];
        $data = [
            'hasPhone' => false,
            'hasEmail' => false,
            'hasContactPage' => false,
        ];

        // Check for phone number (Czech format)
        $data['hasPhone'] = (bool) preg_match('/(?:\+420\s*)?(?:\d{3}\s*){3}|\+420[\s-]?\d{3}[\s-]?\d{3}[\s-]?\d{3}/u', $content);

        // Check for email
        $data['hasEmail'] = (bool) preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $content);

        // Check for contact page link
        $data['hasContactPage'] = (bool) preg_match('/href=["\'][^"\']*(?:kontakt|contact)["\']|(?:>kontakt<|>contact<)/iu', $content);

        $hasContactInfo = $data['hasPhone'] || $data['hasEmail'] || $data['hasContactPage'];

        if (!$hasContactInfo) {
            $issues[] = $this->createIssue('eshop_no_contact_info', 'Nenalezeny kontaktní informace (telefon, e-mail)');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for return policy information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkReturnPolicy(string $content): array
    {
        $issues = [];
        $data = [
            'hasReturnPolicy' => false,
            'hasReturnPeriod' => false,
        ];

        // Check for return policy
        $data['hasReturnPolicy'] = (bool) preg_match('/(?:vrácení\s*zboží|reklamace|vrátit\s*zboží|return\s*policy|odstoupení\s*od\s*smlouvy)/iu', $content);

        // Check for specific return period mention (14 days is standard in EU/CZ)
        $data['hasReturnPeriod'] = (bool) preg_match('/(?:14\s*(?:dní|dnů|days)|30\s*(?:dní|dnů|days))/iu', $content);

        if (!$data['hasReturnPolicy']) {
            $issues[] = $this->createIssue('eshop_no_return_policy', 'Nenalezeny informace o možnosti vrácení zboží');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
