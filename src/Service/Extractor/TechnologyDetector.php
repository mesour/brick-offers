<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class TechnologyDetector
{
    // CMS detection patterns - ordered from most specific to least specific
    private const CMS_PATTERNS = [
        // Czech CMS - check these first (more specific)
        'vismo' => [
            'patterns' => ['vismo.cz', 'redakční systém vismo', 'redakcni system vismo', 'cms vismo'],
            'meta' => '/generator.*Vismo/i',
        ],
        'shoptet' => [
            'patterns' => ['/user/documents/', 'shoptet.cz', 'cdn.myshoptet.com'],
            'meta' => '/generator.*Shoptet/i',
        ],
        'eshop-rychle' => [
            'patterns' => ['eshop-rychle.cz', 'cdn.eshop-rychle.cz'],
        ],
        'webareal' => [
            'patterns' => ['webareal.cz', 'webareal.com'],
        ],
        'webgarden' => [
            'patterns' => ['webgarden.cz'],
        ],
        'estranky' => [
            'patterns' => ['estranky.cz', 'estranky.sk'],
        ],
        'webzdarma' => [
            'patterns' => ['webzdarma.cz'],
        ],
        'webnode' => [
            'patterns' => ['webnode.cz', 'webnode.com', 'webnode.page'],
        ],
        // International CMS
        'wordpress' => [
            'patterns' => ['/wp-content/', '/wp-includes/', '/wp-json/', 'wp-emoji'],
            'meta' => '/generator.*WordPress/i',
        ],
        'wix' => [
            'patterns' => ['static.wixstatic.com', '_wix_', 'wixsite.com', 'wixpress.com'],
            'meta' => '/generator.*Wix/i',
        ],
        'squarespace' => [
            'patterns' => ['squarespace.com', 'sqsp.net', 'static1.squarespace.com'],
            'meta' => '/generator.*Squarespace/i',
        ],
        'shopify' => [
            'patterns' => ['cdn.shopify.com', 'myshopify.com', 'shopify.com'],
            'meta' => '/generator.*Shopify/i',
        ],
        'joomla' => [
            'patterns' => ['/media/jui/', '/media/system/js/'],
            'meta' => '/generator.*Joomla/i',
        ],
        'drupal' => [
            'patterns' => ['/sites/default/files/', '/sites/all/modules/', 'drupal.js', 'Drupal.settings'],
            'meta' => '/generator.*Drupal/i',
            'headers' => ['x-drupal-cache' => '/.*/'],
        ],
        'magento' => [
            'patterns' => ['/static/frontend/', 'mage/cookies.js', 'Magento_'],
            'headers' => ['x-magento-' => '/.*/'],
        ],
        'opencart' => [
            'patterns' => ['catalog/view/javascript/common.js', 'index.php?route=product'],
        ],
        // PrestaShop - more specific patterns to avoid false positives
        'prestashop' => [
            'patterns' => ['/modules/ps_', '/themes/classic/', 'prestashop.com', 'PrestaShop'],
            'meta' => '/generator.*PrestaShop/i',
        ],
    ];

    // Technology detection patterns
    private const TECH_PATTERNS = [
        'jquery' => '/jquery[\-.]?\d|jquery\.min\.js/i',
        'bootstrap' => '/bootstrap[\-.]?\d|bootstrap\.min\.(js|css)/i',
        'react' => '/react[\-.]production|react[\-.]development|react\.min\.js|reactDOM/i',
        'vue' => '/vue[\-.]?\d|vue\.min\.js|vue\.runtime/i',
        'angular' => '/angular[\-.]?\d|angular\.min\.js|ng-app/i',
        'tailwind' => '/tailwind|tailwindcss/i',
        'google_analytics' => '/gtag\(|google-analytics\.com|ga\.js|analytics\.js|googletagmanager\.com\/gtag/i',
        'google_tag_manager' => '/googletagmanager\.com\/gtm/i',
        'facebook_pixel' => '/fbevents\.js|facebook\.net\/.*\/fbevents|fbq\(/i',
        'hotjar' => '/hotjar\.com|static\.hotjar\.com/i',
        'matomo' => '/matomo\.js|piwik\.js/i',
        'recaptcha' => '/google\.com\/recaptcha|grecaptcha/i',
        'cloudflare' => '/cdnjs\.cloudflare\.com|cloudflare\.com/i',
        'font_awesome' => '/font-?awesome/i',
        'google_fonts' => '/fonts\.googleapis\.com|fonts\.gstatic\.com/i',
        'modernizr' => '/modernizr/i',
        'lodash' => '/lodash/i',
        'moment' => '/moment\.js|moment\.min\.js/i',
        'axios' => '/axios\.min\.js|axios/i',
        'webpack' => '/webpack/i',
        'vite' => '/vite/i',
        'next' => '/_next\//i',
        'nuxt' => '/_nuxt\//i',
        'gatsby' => '/gatsby/i',
        'laravel' => '/laravel|app\.js.*csrf/i',
        'symfony' => '/bundles\/.*\.js|sf2/i',
        'swiper' => '/swiper/i',
        'slick' => '/slick/i',
        'lightbox' => '/lightbox/i',
        'fancybox' => '/fancybox/i',
        'owl_carousel' => '/owl\.carousel/i',
        'aos' => '/aos\.js|aos\.css/i',
        'gsap' => '/gsap/i',
        'three_js' => '/three\.js|three\.min\.js/i',
        'leaflet' => '/leaflet/i',
        'mapbox' => '/mapbox/i',
    ];

    /**
     * Detect CMS from HTML content and HTTP headers.
     *
     * @param array<string, array<string>> $headers
     */
    public function detectCms(string $html, array $headers = []): ?string
    {
        foreach (self::CMS_PATTERNS as $cms => $config) {
            // Check content patterns
            if (isset($config['patterns'])) {
                foreach ($config['patterns'] as $pattern) {
                    if (stripos($html, $pattern) !== false) {
                        return $cms;
                    }
                }
            }

            // Check meta generator tag
            if (isset($config['meta']) && preg_match($config['meta'], $html)) {
                return $cms;
            }

            // Check HTTP headers
            if (isset($config['headers']) && !empty($headers)) {
                foreach ($config['headers'] as $headerName => $headerPattern) {
                    $headerValues = $this->getHeaderValues($headers, $headerName);
                    foreach ($headerValues as $value) {
                        if (preg_match($headerPattern, $value)) {
                            return $cms;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Detect technologies/libraries used on the page.
     *
     * @return array<string>
     */
    public function detectTechnologies(string $html): array
    {
        $detected = [];

        foreach (self::TECH_PATTERNS as $tech => $pattern) {
            if (preg_match($pattern, $html)) {
                $detected[] = $tech;
            }
        }

        return $detected;
    }

    /**
     * Get all technology data.
     *
     * @param array<string, array<string>> $headers
     * @return array{cms: string|null, technologies: array<string>}
     */
    public function detect(string $html, array $headers = []): array
    {
        return [
            'cms' => $this->detectCms($html, $headers),
            'technologies' => $this->detectTechnologies($html),
        ];
    }

    /**
     * Get header values (case-insensitive).
     *
     * @param array<string, array<string>> $headers
     * @return array<string>
     */
    private function getHeaderValues(array $headers, string $name): array
    {
        $name = strtolower($name);

        foreach ($headers as $headerName => $values) {
            if (strtolower($headerName) === $name || str_starts_with(strtolower($headerName), $name)) {
                return $values;
            }
        }

        return [];
    }
}
