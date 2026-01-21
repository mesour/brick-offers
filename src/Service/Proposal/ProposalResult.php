<?php

declare(strict_types=1);

namespace App\Service\Proposal;

/**
 * Result of proposal generation.
 */
readonly class ProposalResult
{
    /**
     * @param array<string, string> $outputs Generated output URLs (html, screenshot, pdf)
     * @param array<string, mixed> $aiMetadata AI generation metadata
     */
    public function __construct(
        public string $title,
        public string $content,
        public ?string $summary,
        public array $outputs,
        public array $aiMetadata,
        public bool $success,
        public ?string $error = null,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(
            title: '',
            content: '',
            summary: null,
            outputs: [],
            aiMetadata: ['error' => $message],
            success: false,
            error: $message,
        );
    }

    public function getOutput(string $key): ?string
    {
        return $this->outputs[$key] ?? null;
    }

    public function hasOutput(string $key): bool
    {
        return isset($this->outputs[$key]);
    }
}
