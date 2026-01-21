<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Extractor;

use App\Service\Extractor\PhoneExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhoneExtractor::class)]
final class PhoneExtractorTest extends TestCase
{
    private PhoneExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PhoneExtractor();
    }

    // ==================== Basic Extraction Tests ====================

    #[Test]
    public function extract_emptyHtml_returnsEmptyArray(): void
    {
        $result = $this->extractor->extract('');

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_noPhones_returnsEmptyArray(): void
    {
        $html = '<html><body><p>Hello World!</p></body></html>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    // ==================== Czech Phone Format Tests ====================

    #[Test]
    #[DataProvider('validCzechPhonesProvider')]
    public function extract_validCzechPhone_returnsNormalized(string $input, string $expected): void
    {
        $html = "<p>Tel: {$input}</p>";

        $result = $this->extractor->extract($html);

        self::assertContains($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCzechPhonesProvider(): iterable
    {
        // International format with +420 - using valid prefixes (3, 4, 5, 6, 7)
        yield 'international format spaced' => ['+420 601 234 567', '+420601234567'];
        yield 'international format dashed' => ['+420-602-345-678', '+420602345678'];
        yield 'international format dotted' => ['+420.603.456.789', '+420603456789'];
        yield 'international format compact' => ['+420604567890', '+420604567890'];

        // With 00420 prefix
        yield '00420 spaced' => ['00420 605 234 567', '+420605234567'];
        yield '00420 compact' => ['00420606345678', '+420606345678'];

        // Local format (9 digits) - must start with valid prefix
        yield 'local spaced' => ['607 456 789', '+420607456789'];
        yield 'local compact' => ['608567890', '+420608567890'];
        yield 'local dashed' => ['609-456-789', '+420609456789'];

        // Mobile prefixes (6xx, 7xx)
        yield 'mobile 6xx' => ['+420 601 234 567', '+420601234567'];
        yield 'mobile 7xx' => ['+420 777 888 999', '+420777888999'];

        // Landline prefixes (3xx, 4xx, 5xx work; 2xx is excluded by implementation)
        yield 'regional 3xx' => ['+420 321 234 567', '+420321234567'];
        yield 'regional 4xx' => ['+420 412 345 678', '+420412345678'];
        yield 'regional 5xx' => ['+420 541 234 567', '+420541234567'];
    }

    // ==================== Tel Link Tests ====================

    #[Test]
    public function extract_telLink_extractsPhone(): void
    {
        $html = '<a href="tel:+420601234567">Call us</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420601234567', $result);
    }

    #[Test]
    public function extract_telLinkWithSpaces_extractsPhone(): void
    {
        $html = '<a href="tel: +420 602 345 678">Call us</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420602345678', $result);
    }

    #[Test]
    public function extract_telLinkLocalFormat_extractsPhone(): void
    {
        $html = '<a href="tel:603456789">Call us</a>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420603456789', $result);
    }

    // ==================== Invalid Phone Tests ====================

    #[Test]
    public function extract_icoNumber_excluded(): void
    {
        // IČO is 8 digits - should not be extracted as phone
        $html = '<p>IČO: 12345678</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_invalidPrefix_excluded(): void
    {
        // Phone starting with 0 or 1 is invalid in Czech Republic
        $html = '<p>Tel: 012 345 678</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_allSameDigits_excluded(): void
    {
        $html = '<p>Tel: 111 111 111</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_sequentialDigits_excluded(): void
    {
        $html = '<p>Tel: 123456789</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_tooShort_excluded(): void
    {
        $html = '<p>Tel: 12345678</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extract_tooLong_excluded(): void
    {
        $html = '<p>Tel: 1234567890</p>';

        $result = $this->extractor->extract($html);

        self::assertSame([], $result);
    }

    // ==================== Duplicate Handling Tests ====================

    #[Test]
    public function extract_duplicatePhones_returnsSingleInstance(): void
    {
        $html = '<p>Tel: +420 601 234 567</p><p>Mobile: +420601234567</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
    }

    #[Test]
    public function extract_samePhoneDifferentFormats_returnsSingleInstance(): void
    {
        $html = '<p>+420 601 234 567 | 601-234-567 | 00420601234567</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
        self::assertContains('+420601234567', $result);
    }

    // ==================== Multiple Phones Tests ====================

    #[Test]
    public function extract_multiplePhones_returnsAll(): void
    {
        $html = '<p>Tel: +420 601 234 567</p><p>Fax: +420 321 234 567</p>';

        $result = $this->extractor->extract($html);

        self::assertCount(2, $result);
        self::assertContains('+420601234567', $result);
        self::assertContains('+420321234567', $result);
    }

    // ==================== HTML Entity Tests ====================

    #[Test]
    public function extract_htmlEntities_decoded(): void
    {
        $html = '<p>Tel: +420&#160;601&#160;234&#160;567</p>'; // &nbsp;

        $result = $this->extractor->extract($html);

        self::assertContains('+420601234567', $result);
    }

    // ==================== Real World HTML Tests ====================

    #[Test]
    public function extract_realWorldFooter_extractsPhones(): void
    {
        $html = <<<HTML
        <footer>
            <div class="contact">
                <p>Telefon: <a href="tel:+420777888999">+420 777 888 999</a></p>
                <p>Pevná linka: 321 234 567</p>
            </div>
        </footer>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertCount(2, $result);
        self::assertContains('+420777888999', $result);
        self::assertContains('+420321234567', $result);
    }

    #[Test]
    public function extract_phoneInMixedContent_extracted(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <p>Email: info@company.cz</p>
            <p>Tel: +420 601 234 567</p>
            <p>IČO: 12345678</p>
            <p>DIČ: CZ12345678</p>
        </body>
        </html>
        HTML;

        $result = $this->extractor->extract($html);

        self::assertCount(1, $result);
        self::assertContains('+420601234567', $result);
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function extract_phoneWithTextAround_extracted(): void
    {
        $html = '<p>Volejte na číslo 601234567 nebo pište na email.</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420601234567', $result);
    }

    #[Test]
    public function extract_phoneInTable_extracted(): void
    {
        $html = '<table><tr><td>Telefon</td><td>+420 601 234 567</td></tr></table>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420601234567', $result);
    }

    #[Test]
    public function extract_phoneWithMixedSeparators_extracted(): void
    {
        $html = '<p>Tel: 601.234-567</p>';

        $result = $this->extractor->extract($html);

        self::assertContains('+420601234567', $result);
    }
}
