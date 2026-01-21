<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Proposal;

use App\Service\Proposal\CostEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CostEstimate::class)]
final class CostEstimateTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_setsAllProperties(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 1000,
            estimatedOutputTokens: 500,
            estimatedCostUsd: 0.05,
            estimatedTimeSeconds: 30,
            model: 'claude-3-opus',
        );

        self::assertSame(1000, $estimate->estimatedInputTokens);
        self::assertSame(500, $estimate->estimatedOutputTokens);
        self::assertSame(0.05, $estimate->estimatedCostUsd);
        self::assertSame(30, $estimate->estimatedTimeSeconds);
        self::assertSame('claude-3-opus', $estimate->model);
    }

    // ==================== getTotalTokens Tests ====================

    #[Test]
    public function getTotalTokens_returnsSumOfInputAndOutput(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 1000,
            estimatedOutputTokens: 500,
            estimatedCostUsd: 0.05,
            estimatedTimeSeconds: 30,
            model: 'claude-3-opus',
        );

        self::assertSame(1500, $estimate->getTotalTokens());
    }

    #[Test]
    public function getTotalTokens_withZeroTokens_returnsZero(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 0,
            estimatedOutputTokens: 0,
            estimatedCostUsd: 0.0,
            estimatedTimeSeconds: 0,
            model: 'test-model',
        );

        self::assertSame(0, $estimate->getTotalTokens());
    }

    #[Test]
    public function getTotalTokens_withLargeNumbers(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 100000,
            estimatedOutputTokens: 50000,
            estimatedCostUsd: 5.0,
            estimatedTimeSeconds: 300,
            model: 'claude-3-opus',
        );

        self::assertSame(150000, $estimate->getTotalTokens());
    }

    // ==================== toArray Tests ====================

    #[Test]
    public function toArray_returnsExpectedStructure(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 1000,
            estimatedOutputTokens: 500,
            estimatedCostUsd: 0.05,
            estimatedTimeSeconds: 30,
            model: 'claude-3-opus',
        );

        $array = $estimate->toArray();

        self::assertIsArray($array);
        self::assertArrayHasKey('estimated_input_tokens', $array);
        self::assertArrayHasKey('estimated_output_tokens', $array);
        self::assertArrayHasKey('estimated_total_tokens', $array);
        self::assertArrayHasKey('estimated_cost_usd', $array);
        self::assertArrayHasKey('estimated_time_seconds', $array);
        self::assertArrayHasKey('model', $array);
    }

    #[Test]
    public function toArray_returnsCorrectValues(): void
    {
        $estimate = new CostEstimate(
            estimatedInputTokens: 1000,
            estimatedOutputTokens: 500,
            estimatedCostUsd: 0.05,
            estimatedTimeSeconds: 30,
            model: 'claude-3-opus',
        );

        $array = $estimate->toArray();

        self::assertSame(1000, $array['estimated_input_tokens']);
        self::assertSame(500, $array['estimated_output_tokens']);
        self::assertSame(1500, $array['estimated_total_tokens']);
        self::assertSame(0.05, $array['estimated_cost_usd']);
        self::assertSame(30, $array['estimated_time_seconds']);
        self::assertSame('claude-3-opus', $array['model']);
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function class_isReadonly(): void
    {
        $reflection = new \ReflectionClass(CostEstimate::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
