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
    public function extract_emailInJavaScript_extracted(): void
    {
        $html = '<script>var email = "info@company.cz";</script>';

        $result = $this->extractor->extract($html);

        self::assertContains('info@company.cz', $result);
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
