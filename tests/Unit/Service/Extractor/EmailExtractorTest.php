<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Extractor;

use App\Service\Extractor\EmailExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailExtractor::class)]
final class EmailExtractorTest extends TestCase
{
    private EmailExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new EmailExtractor();
    }

    // ==================== Basic Extraction Tests ====================

    #[Test]
    public function extract_emptyHtml_returnsEmptyArray(): void
    {
        $result = $this->extractor->extract('');

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_noEmails_returnsEmptyArray(): void
    {
        $html = '<html><body><p>Hello World!</p></body></html>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_simpleEmail_returnsEmail(): void
    {
        $html = '<p>Contact us at info@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_multipleEmails_returnsAll(): void
    {
        $html = '<p>Email: sales@company.cz or support@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(2, $result);
        self::assertContains('sales@company.cz', $result);
        self::assertContains('support@company.cz', $result);
    }

    #[Test]
    public function extract_duplicateEmails_returnsSingleInstance(): void
    {
        $html = '<p>Contact info@company.cz</p><p>Or info@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
        self::assertContains('info@company.cz', $result);
    }

    // ==================== Mailto Link Tests ====================

    #[Test]
    public function extract_mailtoLink_extractsEmail(): void
    {
        $html = '<a href="mailto:contact@firma.cz">Contact Us</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('contact@firma.cz', $result);
    }

    #[Test]
    public function extract_mailtoWithQueryString_extractsCleanEmail(): void
    {
        $html = '<a href="mailto:info@company.cz?subject=Hello">Email</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_mailtoUrlEncoded_decodesEmail(): void
    {
        $html = '<a href="mailto:info%40company.cz">Email</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_htmlEntityEncodedEmail_decodesCorrectly(): void
    {
        $html = '<a href="mailto:info&#64;company.cz">info&#64;company.cz</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_multipleHtmlEntities_decodesAll(): void
    {
        // Pattern from brno-stred.cz
        $html = '
            <td><a href="mailto:olga.plchova&#64;brno-stred.cz">olga.plchova&#64;brno-stred.cz</a></td>
            <td><a href="mailto:podatelna.stred&#64;brno.cz">podatelna.stred&#64;brno.cz</a></td>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('olga.plchova@brno-stred.cz', $result);
        self::assertContains('podatelna.stred@brno.cz', $result);
    }

    // ==================== Case Sensitivity Tests ====================

    #[Test]
    public function extract_mixedCase_normalizesToLowercase(): void
    {
        $html = '<p>Contact: INFO@COMPANY.CZ</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_differentCases_treatedAsDuplicate(): void
    {
        $html = '<p>info@company.cz INFO@COMPANY.CZ Info@Company.Cz</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
    }

    // ==================== Ignored Domains Tests ====================

    #[Test]
    #[DataProvider('ignoredDomainsProvider')]
    public function extract_ignoredDomain_excludesEmail(string $email): void
    {
        $html = "<p>Contact: {$email}</p>";

        $result = $this->extractor->extract($html);

        self::assertNotContains(strtolower($email), $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredDomainsProvider(): iterable
    {
        yield 'example.com' => ['test@example.com'];
        yield 'example.org' => ['test@example.org'];
        yield 'example.net' => ['test@example.net'];
        yield 'domain.tld' => ['user@domain.tld'];
        yield 'domain.com' => ['user@domain.com'];
        yield 'yourdomain.com' => ['user@yourdomain.com'];
        yield 'wixpress.com' => ['auto@wixpress.com'];
        yield 'sentry.io' => ['errors@sentry.io'];
        yield 'test.com' => ['test@test.com'];
        yield 'localhost' => ['admin@localhost'];
    }

    // ==================== Fake Pattern Tests ====================

    #[Test]
    #[DataProvider('fakeEmailsProvider')]
    public function extract_fakeEmailPattern_excludesEmail(string $email): void
    {
        $html = "<p>Contact: {$email}</p>";

        $result = $this->extractor->extract($html);

        self::assertNotContains(strtolower($email), $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fakeEmailsProvider(): iterable
    {
        yield 'your@domain' => ['your@realcompany.cz'];
        yield 'email@domain' => ['email@realcompany.cz'];
        yield 'name@domain' => ['name@realcompany.cz'];
        yield 'user@domain' => ['user@realcompany.cz'];
        yield 'test@domain' => ['test@realcompany.cz'];
        yield 'sample@domain' => ['sample@realcompany.cz'];
        yield 'demo@domain' => ['demo@realcompany.cz'];
    }

    // ==================== Priority Sorting Tests ====================

    #[Test]
    public function extract_infoEmailFirst_hasHighestPriority(): void
    {
        $html = '<p>personal@company.cz info@company.cz sales@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertSame('info@company.cz', $result[0]);
    }

    #[Test]
    public function extract_kontaktEmailFirst_hasHighestPriority(): void
    {
        $html = '<p>personal@company.cz kontakt@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertSame('kontakt@company.cz', $result[0]);
    }

    #[Test]
    public function extract_officeEmailFirst_hasHighestPriority(): void
    {
        $html = '<p>personal@company.cz office@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertSame('office@company.cz', $result[0]);
    }

    #[Test]
    public function extract_salesBeforePersonal_correctOrder(): void
    {
        $html = '<p>john@company.cz sales@company.cz</p>';

        $result = $this->extractor->extract($html);

        // Sales has medium priority (lower than info but higher than personal)
        self::assertSame('sales@company.cz', $result[0]);
    }

    #[Test]
    public function extract_infoBeforeSales_correctOrder(): void
    {
        $html = '<p>sales@company.cz info@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertSame('info@company.cz', $result[0]);
    }

    // ==================== Email Cleaning Tests ====================

    #[Test]
    public function extract_emailWithTrailingPunctuation_cleaned(): void
    {
        $html = '<p>Contact info@company.cz.</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
        self::assertNotContains('info@company.cz.', $result);
    }

    #[Test]
    public function extract_emailWithTrailingComma_cleaned(): void
    {
        $html = '<p>Contact info@company.cz, or call us</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_emailWithTrailingSemicolon_cleaned(): void
    {
        $html = '<p>Contact info@company.cz;</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    // ==================== Complex HTML Tests ====================

    #[Test]
    public function extract_emailInAttribute_extracted(): void
    {
        $html = '<input type="text" value="info@company.cz">';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_emailInJavaScript_notExtracted(): void
    {
        // Script tags are stripped to avoid extracting asset filenames (e.g., image@2x.png)
        // This is intentional - emails in JS are usually configuration, not contact info
        $html = '<script>var email = "info@company.cz";</script>';

        $result = $this->extractor->extract($html);

        self::assertNotContains('info@company.cz', $result);
    }

    #[Test]
    public function extract_realWorldHtml_extractsValidEmails(): void
    {
        $html = <<<HTML
        <html>
        <head><title>Firma s.r.o.</title></head>
        <body>
            <footer>
                <a href="mailto:info@firma.cz">info@firma.cz</a>
                <p>Tel: +420 123 456 789</p>
                <p>IČO: 12345678</p>
            </footer>
        </body>
        </html>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertContains('info@firma.cz', $result);
    }

    // ==================== Invalid Email Tests ====================

    #[Test]
    public function extract_invalidEmailFormat_excluded(): void
    {
        $html = '<p>Contact: not-an-email or @missing.com or missing@</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_emailWithSpaces_notExtracted(): void
    {
        $html = '<p>Contact: info @ company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertNotContains('info @ company.cz', $result);
    }

    // ==================== Image/Asset Filename Tests ====================

    #[Test]
    #[DataProvider('imageFilenameProvider')]
    public function extract_imageFilename_excluded(string $filename): void
    {
        $html = "<img src=\"{$filename}\" alt=\"test\">";

        $result = $this->extractor->extract($html);

        self::assertNotContains(strtolower($filename), $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function imageFilenameProvider(): iterable
    {
        yield 'retina webp' => ['radnice@2x.57766654.webp'];
        yield 'retina png' => ['logo@2x.png'];
        yield 'retina jpg' => ['photo@2x.jpg'];
        yield 'retina jpeg' => ['image@2x.jpeg'];
        yield 'retina gif' => ['banner@2x.gif'];
        yield 'retina svg' => ['icon@2x.svg'];
        yield 'simple @2x' => ['hero@2x.webp'];
        yield '@3x variant' => ['background@3x.png'];
    }

    #[Test]
    public function extract_imgTagWithRetinaImage_excludedFromResults(): void
    {
        $html = '
            <html>
            <body>
            <img src="radnice@2x.57766654.webp" alt="Building">
            <p>Contact us at info@company.cz</p>
            </body>
            </html>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
        self::assertNotContains('radnice@2x.57766654.webp', $result);
    }

    #[Test]
    public function extract_srcsetWithRetinaImages_excludedFromResults(): void
    {
        $html = '
            <img srcset="logo@1x.png 1x, logo@2x.png 2x, logo@3x.png 3x" src="logo.png">
            <p>Email: kontakt@firma.cz</p>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('kontakt@firma.cz', $result);
        self::assertNotContains('logo@1x.png', $result);
        self::assertNotContains('logo@2x.png', $result);
        self::assertNotContains('logo@3x.png', $result);
    }

    #[Test]
    public function extract_cssBackgroundImage_excludedFromResults(): void
    {
        $html = '
            <style>
            .hero { background-image: url(hero@2x.webp); }
            .banner { background: url(banner@2x.jpg); }
            </style>
            <a href="mailto:info@school.cz">Contact</a>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('info@school.cz', $result);
        self::assertNotContains('hero@2x.webp', $result);
        self::assertNotContains('banner@2x.jpg', $result);
    }

    #[Test]
    public function extract_scriptTagContents_excludedFromResults(): void
    {
        $html = '
            <script>
            var config = { image: "asset@2x.png" };
            </script>
            <p>Write to us: podpora@firma.cz</p>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('podpora@firma.cz', $result);
        // Script contents should be stripped before extraction
        self::assertCount(1, $result);
    }

    #[Test]
    public function extract_dataAttributes_excludedFromResults(): void
    {
        $html = '
            <div data-src="lazy@2x.webp" data-background="bg@2x.jpg">
                <p>Email: obchod@company.cz</p>
            </div>
        ';

        $result = $this->extractor->extract($html);

        self::assertContains('obchod@company.cz', $result);
        self::assertNotContains('lazy@2x.webp', $result);
        self::assertNotContains('bg@2x.jpg', $result);
    }

    #[Test]
    #[DataProvider('fileExtensionProvider')]
    public function extract_fileExtensionAsTLD_excluded(string $extension): void
    {
        $html = "<p>file@name.{$extension}</p>";

        $result = $this->extractor->extract($html);

        self::assertNotContains("file@name.{$extension}", $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileExtensionProvider(): iterable
    {
        yield 'png' => ['png'];
        yield 'jpg' => ['jpg'];
        yield 'jpeg' => ['jpeg'];
        yield 'gif' => ['gif'];
        yield 'webp' => ['webp'];
        yield 'svg' => ['svg'];
        yield 'ico' => ['ico'];
        yield 'css' => ['css'];
        yield 'js' => ['js'];
        yield 'woff' => ['woff'];
        yield 'woff2' => ['woff2'];
        yield 'ttf' => ['ttf'];
    }

    #[Test]
    public function extract_complexRetinaFilename_excluded(): void
    {
        // This is the exact case that was reported as bug
        $html = '<img src="radnice@2x.57766654.webp">';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_realWorldPageWithImages_onlyExtractsRealEmails(): void
    {
        $html = <<<HTML
        <html>
        <head>
            <style>.logo { background: url(logo@2x.png); }</style>
        </head>
        <body>
            <img src="hero@2x.webp" srcset="hero@1x.webp 1x, hero@2x.webp 2x">
            <img src="building@2x.57766654.webp" alt="Office">
            <script>var img = "icon@2x.svg";</script>
            <div data-image="lazy@2x.jpg">
                <h1>Contact Us</h1>
                <a href="mailto:info@company.cz">info@company.cz</a>
                <p>Or email us at podpora@company.cz</p>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertCount(2, $result);
        self::assertContains('info@company.cz', $result);
        self::assertContains('podpora@company.cz', $result);
    }

    // ==================== Obfuscated Email Tests ====================

    #[Test]
    public function extract_obfuscatedDataMailAndDomain_extractsEmail(): void
    {
        // Pattern used by brno.cz and similar sites
        $html = '<a href="mailto:" data-mail="info" data-domain="example.cz" data-replace-text="true"></a>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@example.cz', $result);
    }

    #[Test]
    public function extract_obfuscatedReversedOrder_extractsEmail(): void
    {
        // Same pattern but with reversed attribute order
        $html = '<a href="mailto:" data-domain="company.cz" data-mail="kontakt"></a>';

        $result = $this->extractor->extract($html);

        self::assertContains('kontakt@company.cz', $result);
    }

    #[Test]
    public function extract_multipleObfuscatedEmails_extractsAll(): void
    {
        $html = '
            <a href="mailto:" data-mail="posta" data-domain="brno.cz"></a>
            <a href="mailto:" data-mail="informace" data-domain="brno.cz"></a>
            <a href="mailto:" data-mail="tis" data-domain="brno.cz"></a>
        ';

        $result = $this->extractor->extract($html);

        self::assertCount(3, $result);
        self::assertContains('posta@brno.cz', $result);
        self::assertContains('informace@brno.cz', $result);
        self::assertContains('tis@brno.cz', $result);
    }

    #[Test]
    public function extract_mixedObfuscatedAndRegularEmails_extractsAll(): void
    {
        $html = '
            <a href="mailto:regular@company.cz">Regular email</a>
            <a href="mailto:" data-mail="obfuscated" data-domain="company.cz"></a>
            <p>Contact us at text@company.cz</p>
        ';

        $result = $this->extractor->extract($html);

        self::assertCount(3, $result);
        self::assertContains('regular@company.cz', $result);
        self::assertContains('obfuscated@company.cz', $result);
        self::assertContains('text@company.cz', $result);
    }

    #[Test]
    public function extract_obfuscatedWithExtraAttributes_extractsEmail(): void
    {
        // Real-world example with extra attributes
        $html = '<a href="mailto:" class="email-link" data-mail="info" data-domain="firma.cz" data-replace-text="true" target="_blank"></a>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@firma.cz', $result);
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function extract_subdomainEmail_extracted(): void
    {
        $html = '<p>Contact support@mail.company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('support@mail.company.cz', $result);
    }

    #[Test]
    public function extract_dotsInLocalPart_extracted(): void
    {
        $html = '<p>Contact john.doe@company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('john.doe@company.cz', $result);
    }

    #[Test]
    public function extract_numbersInEmail_extracted(): void
    {
        $html = '<p>Contact info123@company2.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info123@company2.cz', $result);
    }

    #[Test]
    public function extract_hyphenInDomain_extracted(): void
    {
        $html = '<p>Contact info@my-company.cz</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@my-company.cz', $result);
    }
}
