<?php

declare(strict_types=1);

namespace App\Service\Proposal;

/**
 * Estimated cost of generating a proposal.
 */
readonly class CostEstimate
{
    public function __construct(
        public int $estimatedInputTokens,
        public int $estimatedOutputTokens,
        public float $estimatedCostUsd,
        public int $estimatedTimeSeconds,
        public string $model,
    ) {
    }

    public function getTotalTokens(): int
    {
        return $this->estimatedInputTokens + $this->estimatedOutputTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'estimated_output_tokens' => $this->estimatedOutputTokens,
            'estimated_total_tokens' => $this->getTotalTokens(),
            'estimated_cost_usd' => $this->estimatedCostUsd,
            'estimated_time_seconds' => $this->estimatedTimeSeconds,
            'model' => $this->model,
        ];
    }
}
