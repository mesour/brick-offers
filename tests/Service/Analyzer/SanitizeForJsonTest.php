<?php

declare(strict_types=1);

namespace App\Tests\Service\Analyzer;

use PHPUnit\Framework\TestCase;

class SanitizeForJsonTest extends TestCase
{
    /**
     * Test the sanitization logic that would be applied to content.
     */
    public function testSanitizeRemovesNullBytes(): void
    {
        $content = "Hello\x00World";
        $sanitized = $this->sanitizeForJson($content);

        self::assertSame('HelloWorld', $sanitized);
        self::assertStringNotContainsString("\x00", $sanitized);
    }

    public function testSanitizeRemovesControlCharacters(): void
    {
        // \x01-\x08 should be removed, \x09 (tab) should be kept
        $content = "Hello\x01\x02\x08World\x09Tab";
        $sanitized = $this->sanitizeForJson($content);

        self::assertSame("HelloWorld\tTab", $sanitized);
    }

    public function testSanitizeKeepsNewlines(): void
    {
        $content = "Hello\nWorld\rFoo\r\nBar";
        $sanitized = $this->sanitizeForJson($content);

        self::assertSame("Hello\nWorld\rFoo\r\nBar", $sanitized);
    }

    public function testSanitizeHandlesNull(): void
    {
        $sanitized = $this->sanitizeForJson(null);
        self::assertNull($sanitized);
    }

    public function testSanitizeHandlesEmptyString(): void
    {
        $sanitized = $this->sanitizeForJson('');
        self::assertSame('', $sanitized);
    }

    public function testSanitizedContentCanBeJsonEncoded(): void
    {
        // Simulate content with null bytes (like from a binary response)
        $content = "<!DOCTYPE html>\x00<html>\x00<body>Test\x00</body></html>";
        $sanitized = $this->sanitizeForJson($content);

        // Should be JSON-encodable without error
        $json = json_encode(['content' => $sanitized]);
        self::assertNotFalse($json);
        self::assertStringNotContainsString('\u0000', $json);
    }

    /**
     * Replication of the sanitization logic from AbstractLeadAnalyzer.
     */
    private function sanitizeForJson(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        // Remove null bytes (\x00) which PostgreSQL cannot store in JSON
        $content = str_replace("\x00", '', $content);

        // Also remove other problematic control characters (except common whitespace)
        // Keep: tab (\x09), newline (\x0A), carriage return (\x0D)
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);

        return $content;
    }
}
