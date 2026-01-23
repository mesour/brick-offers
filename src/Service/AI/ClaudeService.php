<?php

declare(strict_types=1);

namespace App\Service\AI;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for interacting with Claude AI.
 *
 * Supports two modes:
 * - CLI: Uses local `claude` CLI tool (for development)
 * - API: Uses Anthropic HTTP API (for production)
 */
class ClaudeService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
        private readonly bool $useCli = true,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {
    }

    /**
     * Generate content using Claude.
     *
     * @param array<string, mixed> $options Additional options
     */
    public function generate(string $prompt, array $options = []): ClaudeResponse
    {
        $startTime = microtime(true);

        try {
            if ($this->useCli) {
                $response = $this->generateViaCli($prompt, $options);
            } else {
                $response = $this->generateViaApi($prompt, $options);
            }

            $endTime = microtime(true);
            $generationTimeMs = (int) (($endTime - $startTime) * 1000);

            return new ClaudeResponse(
                content: $response['content'],
                success: true,
                model: $response['model'] ?? $this->model,
                inputTokens: $response['input_tokens'] ?? null,
                outputTokens: $response['output_tokens'] ?? null,
                generationTimeMs: $generationTimeMs,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Claude generation failed', [
                'error' => $e->getMessage(),
                'prompt_length' => strlen($prompt),
            ]);

            return ClaudeResponse::error($e->getMessage());
        }
    }

    /**
     * Generate via local Claude CLI.
     *
     * @param array<string, mixed> $options
     * @return array{content: string, model?: string, input_tokens?: int, output_tokens?: int}
     */
    private function generateViaCli(string $prompt, array $options): array
    {
        $this->logger->debug('Generating via Claude CLI', [
            'prompt_length' => strlen($prompt),
        ]);

        // Get system prompt from options or use a simple default
        $systemPrompt = $options['system_prompt'] ?? 'You are a helpful AI assistant. Follow the user instructions precisely.';

        // Write prompt to temp file to handle large prompts and special characters
        $tempFile = tempnam(sys_get_temp_dir(), 'claude_prompt_');
        file_put_contents($tempFile, $prompt);

        try {
            // Use bash to read file content and pipe to claude
            $escapedSystemPrompt = escapeshellarg($systemPrompt);
            $model = $options['model'] ?? $this->model;
            $modelAlias = match (true) {
                str_contains($model, 'opus') => 'opus',
                str_contains($model, 'sonnet') => 'sonnet',
                str_contains($model, 'haiku') => 'haiku',
                default => $model,
            };
            $escapedModel = escapeshellarg($modelAlias);
            $escapedTempFile = escapeshellarg($tempFile);

            $bashCommand = "cat {$escapedTempFile} | claude --print --output-format json --model {$escapedModel} --system-prompt {$escapedSystemPrompt}";

            $process = new Process(['bash', '-c', $bashCommand]);
            $process->setTimeout(300); // 5 minute timeout
            $process->run();

            $this->logger->debug('Claude CLI process completed', [
                'exit_code' => $process->getExitCode(),
                'output_length' => strlen($process->getOutput()),
            ]);

            if (!$process->isSuccessful()) {
                $this->logger->error('Claude CLI process failed', [
                    'exit_code' => $process->getExitCode(),
                    'error_output' => $process->getErrorOutput(),
                    'output' => substr($process->getOutput(), 0, 500),
                ]);
                throw new \RuntimeException('Claude CLI failed: ' . $process->getErrorOutput());
            }

            $this->logger->debug('Claude CLI output', [
                'output_length' => strlen($process->getOutput()),
            ]);

            $output = $process->getOutput();

            // Try to parse JSON output (Claude Code format)
            $json = json_decode($output, true);
            if ($json !== null && isset($json['result'])) {
                // Extract token usage from modelUsage if available
                $inputTokens = null;
                $outputTokens = null;
                $actualModel = $modelAlias;

                if (isset($json['usage'])) {
                    $inputTokens = ($json['usage']['input_tokens'] ?? 0)
                        + ($json['usage']['cache_read_input_tokens'] ?? 0)
                        + ($json['usage']['cache_creation_input_tokens'] ?? 0);
                    $outputTokens = $json['usage']['output_tokens'] ?? null;
                }

                if (isset($json['modelUsage'])) {
                    $firstModel = array_key_first($json['modelUsage']);
                    if ($firstModel) {
                        $actualModel = $firstModel;
                        $usage = $json['modelUsage'][$firstModel];
                        $inputTokens = $inputTokens ?? (($usage['inputTokens'] ?? 0) + ($usage['cacheReadInputTokens'] ?? 0));
                        $outputTokens = $outputTokens ?? ($usage['outputTokens'] ?? null);
                    }
                }

                return [
                    'content' => $json['result'],
                    'model' => $actualModel,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                ];
            }

            // Fallback to raw output
            return ['content' => trim($output)];
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Generate via Anthropic API.
     *
     * @param array<string, mixed> $options
     * @return array{content: string, model?: string, input_tokens?: int, output_tokens?: int}
     */
    private function generateViaApi(string $prompt, array $options): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Claude API key not configured');
        }

        $this->logger->debug('Generating via Claude API', [
            'prompt_length' => strlen($prompt),
            'model' => $options['model'] ?? $this->model,
        ]);

        $model = $options['model'] ?? $this->model;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ],
        ]);

        $data = $response->toArray();

        if (!isset($data['content'][0]['text'])) {
            throw new \RuntimeException('Unexpected API response format');
        }

        return [
            'content' => $data['content'][0]['text'],
            'model' => $data['model'] ?? $model,
            'input_tokens' => $data['usage']['input_tokens'] ?? null,
            'output_tokens' => $data['usage']['output_tokens'] ?? null,
        ];
    }

    /**
     * Check if the service is available.
     */
    public function isAvailable(): bool
    {
        if ($this->useCli) {
            $process = new Process(['which', 'claude']);
            $process->run();

            return $process->isSuccessful();
        }

        return !empty($this->apiKey);
    }

    /**
     * Get the current mode (cli or api).
     */
    public function getMode(): string
    {
        return $this->useCli ? 'cli' : 'api';
    }
}
