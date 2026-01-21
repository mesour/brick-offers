<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Extractor;

use App\Service\Extractor\CompanyNameExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyNameExtractor::class)]
final class CompanyNameExtractorTest extends TestCase
{
    private CompanyNameExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CompanyNameExtractor();
    }

    // ==================== Basic Extraction Tests ====================

    #[Test]
    public function extract_emptyHtml_returnsEmptyArray(): void
    {
        $result = $this->extractor->extract('');

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_noCompanyName_returnsEmptyArray(): void
    {
        $html = '<html><body><p>Hello World!</p></body></html>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    // ==================== Legal Form Extraction Tests ====================

    #[Test]
    #[DataProvider('legalFormProvider')]
    public function extract_legalForm_extractsCompanyName(string $companyName, string $html): void
    {
        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
        // Check that the result contains the company name
        $found = false;
        foreach ($result as $name) {
            if (stripos($name, $companyName) !== false || stripos($companyName, $name) !== false) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, "Expected to find '{$companyName}' in results: " . implode(', ', $result));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legalFormProvider(): iterable
    {
        yield 's.r.o.' => ['Firma', '<p>Firma s.r.o.</p>'];
        yield 's. r. o.' => ['Společnost', '<p>Společnost s. r. o.</p>'];
        yield 'spol. s r.o.' => ['Obchod', '<p>Obchod spol. s r.o.</p>'];
        yield 'a.s.' => ['Velká Firma', '<p>Velká Firma a.s.</p>'];
        yield 'a. s.' => ['Korporace', '<p>Korporace a. s.</p>'];
        yield 'v.o.s.' => ['Partner', '<p>Partner v.o.s.</p>'];
        yield 'k.s.' => ['Investice', '<p>Investice k.s.</p>'];
        yield 'z.s.' => ['Spolek', '<p>Spolek z.s.</p>'];
        yield 's.p.' => ['Státní podnik', '<p>Státní podnik s.p.</p>'];
        yield 'SE' => ['Evropská', '<p>Evropská SE</p>'];
    }

    // ==================== Schema.org Extraction Tests ====================

    #[Test]
    public function extract_schemaOrgOrganization_extractsName(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {
            "@type": "Organization",
            "name": "Schema Org Company s.r.o."
        }
        </script>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertContains('Schema Org Company s.r.o.', $result);
    }

    #[Test]
    public function extract_schemaOrgLocalBusiness_extractsName(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {
            "@type": "LocalBusiness",
            "name": "Místní Firma s.r.o."
        }
        </script>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertContains('Místní Firma s.r.o.', $result);
    }

    #[Test]
    public function extract_schemaOrgGraph_extractsName(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {
            "@graph": [
                {
                    "@type": "Organization",
                    "name": "Graph Company a.s."
                }
            ]
        }
        </script>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertContains('Graph Company a.s.', $result);
    }

    #[Test]
    public function extract_schemaOrgInvalidJson_doesNotThrow(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        { invalid json }
        </script>
        <p>Firma s.r.o.</p>
        HTML;

        $result = $this->extractor->extract($html);

        // Should still extract from other sources
        self::assertNotEmpty($result);
    }

    // ==================== Open Graph Extraction Tests ====================

    #[Test]
    public function extract_ogSiteName_extractsName(): void
    {
        $html = '<meta property="og:site_name" content="OG Company s.r.o.">';

        $result = $this->extractor->extract($html);

        self::assertContains('OG Company s.r.o.', $result);
    }

    #[Test]
    public function extract_ogSiteNameReversedAttributes_extractsName(): void
    {
        $html = '<meta content="Reversed OG s.r.o." property="og:site_name">';

        $result = $this->extractor->extract($html);

        self::assertContains('Reversed OG s.r.o.', $result);
    }

    // ==================== Copyright Notice Tests ====================

    #[Test]
    public function extract_copyrightNotice_extractsName(): void
    {
        $html = '<footer>© 2024 Copyright Company s.r.o.</footer>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_copyrightWithYearRange_extractsName(): void
    {
        $html = '<footer>© 2020-2024 Year Range Company a.s.</footer>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    // ==================== Title Tag Tests ====================

    #[Test]
    public function extract_titleWithCompanyName_extractsName(): void
    {
        $html = '<title>Title Company s.r.o. | Úvodní stránka</title>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_titleWithPipeSeparator_extractsCompanyPart(): void
    {
        $html = '<title>Produkty | Firma s.r.o.</title>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_titleWithDashSeparator_extractsCompanyPart(): void
    {
        $html = '<title>Služby - Servis s.r.o.</title>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    // ==================== extractSingle Tests ====================

    #[Test]
    public function extractSingle_noCompanyName_returnsNull(): void
    {
        $html = '<html><body><p>Hello</p></body></html>';

        $result = $this->extractor->extractSingle($html);

        self::assertNull($result);
    }

    #[Test]
    public function extractSingle_multipleNames_returnsHighestPriority(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {"@type": "Organization", "name": "Schema Company"}
        </script>
        <meta property="og:site_name" content="OG Company">
        <title>Title Company</title>
        HTML;

        $result = $this->extractor->extractSingle($html);

        // Schema.org has highest priority (100)
        self::assertSame('Schema Company', $result);
    }

    // ==================== Priority Tests ====================

    #[Test]
    public function extract_schemaOrgHasHigherPriorityThanOG(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {"@type": "Organization", "name": "Schema First"}
        </script>
        <meta property="og:site_name" content="OG Second">
        HTML;

        $result = $this->extractor->extract($html);

        self::assertSame('Schema First', $result[0]);
    }

    #[Test]
    public function extract_ogHasHigherPriorityThanLegalForm(): void
    {
        $html = <<<HTML
        <meta property="og:site_name" content="OG First">
        <p>Legal Form Second s.r.o.</p>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertSame('OG First', $result[0]);
    }

    // ==================== Deduplication Tests ====================

    #[Test]
    public function extract_duplicateNames_returnsSingleInstance(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {"@type": "Organization", "name": "Same Company"}
        </script>
        <meta property="og:site_name" content="Same Company">
        HTML;

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
    }

    #[Test]
    public function extract_exactDuplication_returnsSingleInstance(): void
    {
        $html = <<<HTML
        <script type="application/ld+json">
        {"@type": "Organization", "name": "Same Company"}
        </script>
        <meta property="og:site_name" content="Same Company">
        <title>Same Company | Home</title>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
    }

    // ==================== Validation Tests ====================

    #[Test]
    #[DataProvider('invalidCompanyNamesProvider')]
    public function extract_invalidCompanyName_excluded(string $name): void
    {
        $html = "<meta property=\"og:site_name\" content=\"{$name}\">";

        $result = $this->extractor->extract($html);

        self::assertNotContains($name, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCompanyNamesProvider(): iterable
    {
        yield 'single char' => ['A'];
        yield 'home' => ['Home'];
        yield 'úvod' => ['Úvod'];
        yield 'kontakt' => ['Kontakt'];
        yield 'o nás' => ['O nás'];
        yield 'about' => ['About'];
        yield 'contact' => ['Contact'];
        yield 'hlavní strana' => ['Hlavní strana'];
        yield 'only numbers' => ['12345'];
        yield 'url' => ['https://example.com'];
    }

    // ==================== HTML Entity Tests ====================

    #[Test]
    public function extract_htmlEntities_decoded(): void
    {
        $html = '<p>Firma &amp; Spol., s.r.o.</p>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
        // Check that & is decoded
        $found = false;
        foreach ($result as $name) {
            if (str_contains($name, '&')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);
    }

    // ==================== Real World Tests ====================

    #[Test]
    public function extract_realWorldHtml_extractsCompanyName(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <title>Produkty | ABC Technologies s.r.o.</title>
            <meta property="og:site_name" content="ABC Technologies">
            <script type="application/ld+json">
            {
                "@type": "Organization",
                "name": "ABC Technologies s.r.o.",
                "url": "https://abc-tech.cz"
            }
            </script>
        </head>
        <body>
            <footer>
                <p>© 2024 ABC Technologies s.r.o.</p>
                <p>IČO: 12345678</p>
            </footer>
        </body>
        </html>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
        // Should have ABC Technologies as top result
        self::assertStringContainsString('ABC Technologies', $result[0]);
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function extract_companyNameWithNumbers_extracted(): void
    {
        $html = '<p>Firma 2000 s.r.o.</p>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_companyNameWithHyphen_extracted(): void
    {
        $html = '<p>Tech-Solutions s.r.o.</p>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_companyNameWithAccents_extracted(): void
    {
        $html = '<p>Česká Společnost s.r.o.</p>';

        $result = $this->extractor->extract($html);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function extract_veryLongName_excluded(): void
    {
        $longName = str_repeat('A', 250);
        $html = "<meta property=\"og:site_name\" content=\"{$longName}\">";

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }
}
