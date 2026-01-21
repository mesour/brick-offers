<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Extractor;

use App\Service\Extractor\TechnologyDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TechnologyDetector::class)]
final class TechnologyDetectorTest extends TestCase
{
    private TechnologyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TechnologyDetector();
    }

    // ==================== detectCms Tests ====================

    #[Test]
    public function detectCms_emptyHtml_returnsNull(): void
    {
        $result = $this->detector->detectCms('');

        self::assertNull($result);
    }

    #[Test]
    public function detectCms_noKnownCms_returnsNull(): void
    {
        $html = '<html><body><p>Just a simple page</p></body></html>';

        $result = $this->detector->detectCms($html);

        self::assertNull($result);
    }

    #[Test]
    #[DataProvider('cmsPatternProvider')]
    public function detectCms_knownCms_detectsCorrectly(string $cms, string $html): void
    {
        $result = $this->detector->detectCms($html);

        self::assertSame($cms, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cmsPatternProvider(): iterable
    {
        // WordPress
        yield 'wordpress wp-content' => ['wordpress', '<link href="/wp-content/themes/theme/style.css">'];
        yield 'wordpress wp-includes' => ['wordpress', '<script src="/wp-includes/js/jquery.js"></script>'];
        yield 'wordpress wp-json' => ['wordpress', '<link rel="alternate" href="/wp-json/">'];
        yield 'wordpress meta generator' => ['wordpress', '<meta name="generator" content="WordPress 6.4">'];

        // Shoptet
        yield 'shoptet pattern' => ['shoptet', '<link href="https://cdn.myshoptet.com/style.css">'];
        yield 'shoptet generator' => ['shoptet', '<meta name="generator" content="Shoptet">'];

        // Wix
        yield 'wix static' => ['wix', '<img src="https://static.wixstatic.com/image.jpg">'];
        yield 'wix pattern' => ['wix', '<script>window._wix_init();</script>'];

        // Squarespace
        yield 'squarespace static' => ['squarespace', '<link href="https://static1.squarespace.com/static/css">'];
        yield 'squarespace generator' => ['squarespace', '<meta name="generator" content="Squarespace">'];

        // PrestaShop
        yield 'prestashop generator' => ['prestashop', '<meta name="generator" content="PrestaShop">'];

        // Webnode
        yield 'webnode pattern' => ['webnode', '<script src="https://webnode.cz/script.js"></script>'];

        // Shopify
        yield 'shopify cdn' => ['shopify', '<link href="https://cdn.shopify.com/s/files/style.css">'];
        yield 'shopify generator' => ['shopify', '<meta name="generator" content="Shopify">'];

        // Joomla
        yield 'joomla media' => ['joomla', '<script src="/media/jui/js/jquery.min.js"></script>'];
        yield 'joomla generator' => ['joomla', '<meta name="generator" content="Joomla! 4.0">'];

        // Drupal
        yield 'drupal sites' => ['drupal', '<link href="/sites/default/files/css/style.css">'];
        yield 'drupal generator' => ['drupal', '<meta name="generator" content="Drupal 10">'];

        // Magento
        yield 'magento static' => ['magento', '<script src="/static/frontend/Magento/luma/js/app.js"></script>'];
        yield 'magento cookies' => ['magento', '<script src="/js/mage/cookies.js"></script>'];

        // OpenCart
        yield 'opencart pattern' => ['opencart', '<link href="catalog/view/javascript/jquery/owl-carousel/owl.carousel.css">'];

        // Czech specific
        yield 'eshop-rychle' => ['eshop-rychle', '<script src="https://cdn.eshop-rychle.cz/script.js"></script>'];
        yield 'webareal' => ['webareal', '<link href="https://webareal.cz/styles.css">'];
        yield 'webgarden' => ['webgarden', '<script src="https://webgarden.cz/js/app.js"></script>'];
        yield 'estranky' => ['estranky', '<img src="https://estranky.cz/logo.png">'];
        yield 'webzdarma' => ['webzdarma', '<link href="https://webzdarma.cz/style.css">'];
    }

    #[Test]
    public function detectCms_withHeaders_detectsWordPress(): void
    {
        $html = '<html><body>Page</body></html>';
        $headers = ['x-powered-by' => ['PHP/8.2']];

        $result = $this->detector->detectCms($html, $headers);

        self::assertSame('wordpress', $result);
    }

    #[Test]
    public function detectCms_drupalHeader_detectsDrupal(): void
    {
        $html = '<html><body>Page</body></html>';
        $headers = ['x-drupal-cache' => ['HIT']];

        $result = $this->detector->detectCms($html, $headers);

        self::assertSame('drupal', $result);
    }

    // ==================== detectTechnologies Tests ====================

    #[Test]
    public function detectTechnologies_emptyHtml_returnsEmptyArray(): void
    {
        $result = $this->detector->detectTechnologies('');

        self::assertSame([], $result);
    }

    #[Test]
    public function detectTechnologies_noKnownTech_returnsEmptyArray(): void
    {
        $html = '<html><body><p>Just text</p></body></html>';

        $result = $this->detector->detectTechnologies($html);

        self::assertSame([], $result);
    }

    #[Test]
    #[DataProvider('technologyPatternProvider')]
    public function detectTechnologies_knownTech_detectsCorrectly(string $tech, string $html): void
    {
        $result = $this->detector->detectTechnologies($html);

        self::assertContains($tech, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function technologyPatternProvider(): iterable
    {
        // JavaScript frameworks/libraries
        yield 'jquery' => ['jquery', '<script src="/js/jquery.min.js"></script>'];
        yield 'bootstrap' => ['bootstrap', '<link href="bootstrap.min.css">'];
        yield 'react' => ['react', '<script src="react.production.min.js"></script>'];
        yield 'vue' => ['vue', '<script src="vue.min.js"></script>'];
        yield 'angular' => ['angular', '<script src="angular.min.js"></script>'];
        yield 'tailwind' => ['tailwind', '<link href="tailwindcss/dist/tailwind.css">'];

        // Analytics
        yield 'google_analytics gtag' => ['google_analytics', '<script>gtag("config", "G-123");</script>'];
        yield 'google_analytics ga.js' => ['google_analytics', '<script src="https://google-analytics.com/ga.js"></script>'];
        yield 'google_tag_manager' => ['google_tag_manager', '<script src="https://googletagmanager.com/gtm.js"></script>'];
        yield 'facebook_pixel' => ['facebook_pixel', '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'];
        yield 'hotjar' => ['hotjar', '<script src="https://static.hotjar.com/c/hotjar-123.js"></script>'];
        yield 'matomo' => ['matomo', '<script src="/matomo.js"></script>'];

        // CDN/Services
        yield 'cloudflare' => ['cloudflare', '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.js"></script>'];
        yield 'recaptcha' => ['recaptcha', '<script src="https://www.google.com/recaptcha/api.js"></script>'];

        // Fonts/Icons
        yield 'font_awesome' => ['font_awesome', '<link href="font-awesome/css/all.css">'];
        yield 'google_fonts' => ['google_fonts', '<link href="https://fonts.googleapis.com/css?family=Roboto">'];

        // Build tools/Frameworks
        yield 'webpack' => ['webpack', '/* webpack bundle */'];
        yield 'vite' => ['vite', '<script type="module" src="/vite/client"></script>'];
        yield 'next' => ['next', '<script src="/_next/static/chunks/main.js"></script>'];
        yield 'nuxt' => ['nuxt', '<script src="/_nuxt/app.js"></script>'];
        yield 'gatsby' => ['gatsby', '<script src="/gatsby-chunk.js"></script>'];

        // Backend frameworks
        yield 'laravel' => ['laravel', '<meta name="csrf-token" content="laravel-token">'];
        yield 'symfony' => ['symfony', '<script src="/bundles/framework/js/app.js"></script>'];

        // UI Libraries
        yield 'swiper' => ['swiper', '<script src="swiper.min.js"></script>'];
        yield 'slick' => ['slick', '<link href="slick.css">'];
        yield 'lightbox' => ['lightbox', '<script src="lightbox.js"></script>'];
        yield 'fancybox' => ['fancybox', '<script src="jquery.fancybox.min.js"></script>'];
        yield 'owl_carousel' => ['owl_carousel', '<script src="owl.carousel.min.js"></script>'];

        // Animation
        yield 'aos' => ['aos', '<link href="aos.css">'];
        yield 'gsap' => ['gsap', '<script src="gsap.min.js"></script>'];

        // 3D/Maps
        yield 'three_js' => ['three_js', '<script src="three.min.js"></script>'];
        yield 'leaflet' => ['leaflet', '<script src="leaflet.js"></script>'];
        yield 'mapbox' => ['mapbox', '<script src="https://api.mapbox.com/mapbox.js"></script>'];

        // Utilities
        yield 'modernizr' => ['modernizr', '<script src="modernizr.js"></script>'];
        yield 'lodash' => ['lodash', '<script src="lodash.min.js"></script>'];
        yield 'moment' => ['moment', '<script src="moment.min.js"></script>'];
        yield 'axios' => ['axios', '<script src="axios.min.js"></script>'];
    }

    #[Test]
    public function detectTechnologies_multipleTech_detectsAll(): void
    {
        $html = <<<HTML
        <html>
        <head>
            <script src="jquery.min.js"></script>
            <link href="bootstrap.min.css">
            <script src="https://fonts.googleapis.com/css"></script>
        </head>
        </html>
        HTML;

        $result = $this->detector->detectTechnologies($html);

        self::assertContains('jquery', $result);
        self::assertContains('bootstrap', $result);
        self::assertContains('google_fonts', $result);
    }

    // ==================== detect Tests ====================

    #[Test]
    public function detect_emptyHtml_returnsEmptyResult(): void
    {
        $result = $this->detector->detect('');

        self::assertNull($result['cms']);
        self::assertSame([], $result['technologies']);
    }

    #[Test]
    public function detect_wordPressWithTech_returnsAll(): void
    {
        $html = <<<HTML
        <html>
        <head>
            <link href="/wp-content/themes/theme/style.css">
            <script src="jquery.min.js"></script>
            <link href="bootstrap.min.css">
        </head>
        </html>
        HTML;

        $result = $this->detector->detect($html);

        self::assertSame('wordpress', $result['cms']);
        self::assertContains('jquery', $result['technologies']);
        self::assertContains('bootstrap', $result['technologies']);
    }

    #[Test]
    public function detect_withHeaders_includesHeaderDetection(): void
    {
        $html = '<html><body>Page</body></html>';
        $headers = ['x-drupal-cache' => ['HIT']];

        $result = $this->detector->detect($html, $headers);

        self::assertSame('drupal', $result['cms']);
    }

    // ==================== Real World Tests ====================

    #[Test]
    public function detect_realWorldWordPressSite_detectsCorrectly(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="generator" content="WordPress 6.4.2">
            <link rel="stylesheet" href="/wp-content/themes/theme/style.css">
            <script src="/wp-includes/js/jquery/jquery.min.js"></script>
            <script src="https://www.googletagmanager.com/gtm.js?id=GTM-123"></script>
            <link href="https://fonts.googleapis.com/css?family=Open+Sans">
        </head>
        <body>
            <div class="container">Content</div>
        </body>
        </html>
        HTML;

        $result = $this->detector->detect($html);

        self::assertSame('wordpress', $result['cms']);
        self::assertContains('jquery', $result['technologies']);
        self::assertContains('google_tag_manager', $result['technologies']);
        self::assertContains('google_fonts', $result['technologies']);
    }

    #[Test]
    public function detect_realWorldShoptetSite_detectsCorrectly(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta name="generator" content="Shoptet">
            <link href="https://cdn.myshoptet.com/usr/shop/css/style.css">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
        </head>
        <body>
            <div class="product-list">Products</div>
        </body>
        </html>
        HTML;

        $result = $this->detector->detect($html);

        self::assertSame('shoptet', $result['cms']);
        self::assertContains('jquery', $result['technologies']);
        self::assertContains('cloudflare', $result['technologies']);
    }

    // ==================== Header Case Insensitivity Tests ====================

    #[Test]
    public function detectCms_headersCaseInsensitive_works(): void
    {
        $html = '<html><body>Page</body></html>';
        $headers = ['X-Powered-By' => ['PHP/8.2']];

        $result = $this->detector->detectCms($html, $headers);

        self::assertSame('wordpress', $result);
    }
}
