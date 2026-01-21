<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Response from Claude AI generation.
 */
readonly class ClaudeResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $content,
        public bool $success,
        public ?string $error = null,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $generationTimeMs = null,
        public array $metadata = [],
    ) {
    }

    public function getTotalTokens(): int
    {
        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAiMetadata(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->getTotalTokens(),
            'generation_time_ms' => $this->generationTimeMs,
            'success' => $this->success,
            'error' => $this->error,
            ...$this->metadata,
        ];
    }

    public static function error(string $message): self
    {
        return new self(
            content: '',
            success: false,
            error: $message,
        );
    }
}
