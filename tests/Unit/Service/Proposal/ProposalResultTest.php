<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Proposal;

use App\Service\Proposal\ProposalResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposalResult::class)]
final class ProposalResultTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_setsAllProperties(): void
    {
        $result = new ProposalResult(
            title: 'Test Title',
            content: '<h1>Content</h1>',
            summary: 'Brief summary',
            outputs: ['html_url' => 'https://example.com/proposal.html'],
            aiMetadata: ['model' => 'claude-3-opus'],
            success: true,
            error: null,
        );

        self::assertSame('Test Title', $result->title);
        self::assertSame('<h1>Content</h1>', $result->content);
        self::assertSame('Brief summary', $result->summary);
        self::assertSame(['html_url' => 'https://example.com/proposal.html'], $result->outputs);
        self::assertSame(['model' => 'claude-3-opus'], $result->aiMetadata);
        self::assertTrue($result->success);
        self::assertNull($result->error);
    }

    #[Test]
    public function constructor_withNullSummary(): void
    {
        $result = new ProposalResult(
            title: 'Test Title',
            content: 'Content',
            summary: null,
            outputs: [],
            aiMetadata: [],
            success: true,
        );

        self::assertNull($result->summary);
    }

    #[Test]
    public function constructor_withError(): void
    {
        $result = new ProposalResult(
            title: '',
            content: '',
            summary: null,
            outputs: [],
            aiMetadata: [],
            success: false,
            error: 'Generation failed',
        );

        self::assertFalse($result->success);
        self::assertSame('Generation failed', $result->error);
    }

    // ==================== error() Factory Tests ====================

    #[Test]
    public function error_createsFailedResult(): void
    {
        $result = ProposalResult::error('Something went wrong');

        self::assertFalse($result->success);
        self::assertSame('Something went wrong', $result->error);
    }

    #[Test]
    public function error_setsEmptyTitle(): void
    {
        $result = ProposalResult::error('Error message');

        self::assertSame('', $result->title);
    }

    #[Test]
    public function error_setsEmptyContent(): void
    {
        $result = ProposalResult::error('Error message');

        self::assertSame('', $result->content);
    }

    #[Test]
    public function error_setsNullSummary(): void
    {
        $result = ProposalResult::error('Error message');

        self::assertNull($result->summary);
    }

    #[Test]
    public function error_setsEmptyOutputs(): void
    {
        $result = ProposalResult::error('Error message');

        self::assertSame([], $result->outputs);
    }

    #[Test]
    public function error_setsErrorInAiMetadata(): void
    {
        $result = ProposalResult::error('Generation timeout');

        self::assertArrayHasKey('error', $result->aiMetadata);
        self::assertSame('Generation timeout', $result->aiMetadata['error']);
    }

    // ==================== getOutput Tests ====================

    #[Test]
    public function getOutput_existingKey_returnsValue(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: [
                'html_url' => 'https://example.com/proposal.html',
                'pdf_url' => 'https://example.com/proposal.pdf',
            ],
            aiMetadata: [],
            success: true,
        );

        self::assertSame('https://example.com/proposal.html', $result->getOutput('html_url'));
        self::assertSame('https://example.com/proposal.pdf', $result->getOutput('pdf_url'));
    }

    #[Test]
    public function getOutput_nonExistingKey_returnsNull(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: ['html_url' => 'https://example.com/proposal.html'],
            aiMetadata: [],
            success: true,
        );

        self::assertNull($result->getOutput('screenshot_url'));
    }

    #[Test]
    public function getOutput_emptyOutputs_returnsNull(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: [],
            aiMetadata: [],
            success: true,
        );

        self::assertNull($result->getOutput('any_key'));
    }

    // ==================== hasOutput Tests ====================

    #[Test]
    public function hasOutput_existingKey_returnsTrue(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: ['html_url' => 'https://example.com/proposal.html'],
            aiMetadata: [],
            success: true,
        );

        self::assertTrue($result->hasOutput('html_url'));
    }

    #[Test]
    public function hasOutput_nonExistingKey_returnsFalse(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: ['html_url' => 'https://example.com/proposal.html'],
            aiMetadata: [],
            success: true,
        );

        self::assertFalse($result->hasOutput('pdf_url'));
    }

    #[Test]
    public function hasOutput_emptyOutputs_returnsFalse(): void
    {
        $result = new ProposalResult(
            title: 'Title',
            content: 'Content',
            summary: null,
            outputs: [],
            aiMetadata: [],
            success: true,
        );

        self::assertFalse($result->hasOutput('any_key'));
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function class_isReadonly(): void
    {
        $reflection = new \ReflectionClass(ProposalResult::class);

        self::assertTrue($reflection->isReadOnly());
    }

    // ==================== Integration Tests ====================

    #[Test]
    public function successfulResult_hasExpectedState(): void
    {
        $result = new ProposalResult(
            title: 'Modern Design Proposal',
            content: '<html>...</html>',
            summary: 'A modern, responsive design for your website.',
            outputs: [
                'html_url' => 'https://cdn.example.com/proposals/123/design.html',
                'screenshot_url' => 'https://cdn.example.com/proposals/123/screenshot.png',
                'pdf_url' => 'https://cdn.example.com/proposals/123/design.pdf',
            ],
            aiMetadata: [
                'model' => 'claude-3-opus',
                'tokens_used' => 2500,
                'generation_time_ms' => 12000,
            ],
            success: true,
        );

        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertNotEmpty($result->title);
        self::assertNotEmpty($result->content);
        self::assertTrue($result->hasOutput('html_url'));
        self::assertTrue($result->hasOutput('screenshot_url'));
        self::assertTrue($result->hasOutput('pdf_url'));
    }

    #[Test]
    public function failedResult_hasExpectedState(): void
    {
        $result = ProposalResult::error('API rate limit exceeded');

        self::assertFalse($result->success);
        self::assertSame('API rate limit exceeded', $result->error);
        self::assertEmpty($result->title);
        self::assertEmpty($result->content);
        self::assertNull($result->summary);
        self::assertEmpty($result->outputs);
        self::assertArrayHasKey('error', $result->aiMetadata);
    }
}
