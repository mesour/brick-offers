<?php

declare(strict_types=1);

namespace App\Service\Proposal;

use App\Entity\Analysis;
use App\Service\AI\ClaudeService;
use App\Service\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for proposal generators.
 */
abstract class AbstractProposalGenerator implements ProposalGeneratorInterface
{
    protected const DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    protected const COST_PER_1K_INPUT_TOKENS = 0.003; // USD
    protected const COST_PER_1K_OUTPUT_TOKENS = 0.015; // USD

    public function __construct(
        protected readonly ClaudeService $claude,
        protected readonly StorageInterface $storage,
        protected readonly LoggerInterface $logger,
        protected readonly string $promptsDir = '',
    ) {
    }

    public function estimateCost(Analysis $analysis): CostEstimate
    {
        $prompt = $this->buildPrompt($analysis);
        $estimatedInputTokens = (int) (strlen($prompt) / 4); // Rough estimate
        $estimatedOutputTokens = 2000; // Average expected output

        $inputCost = ($estimatedInputTokens / 1000) * self::COST_PER_1K_INPUT_TOKENS;
        $outputCost = ($estimatedOutputTokens / 1000) * self::COST_PER_1K_OUTPUT_TOKENS;

        return new CostEstimate(
            estimatedInputTokens: $estimatedInputTokens,
            estimatedOutputTokens: $estimatedOutputTokens,
            estimatedCostUsd: $inputCost + $outputCost,
            estimatedTimeSeconds: 30,
            model: static::DEFAULT_MODEL,
        );
    }

    /**
     * Build the prompt for Claude.
     */
    abstract protected function buildPrompt(Analysis $analysis): string;

    /**
     * Process the raw Claude response into a ProposalResult.
     */
    abstract protected function processResponse(string $response, Analysis $analysis): ProposalResult;

    /**
     * Load a prompt template from file.
     */
    protected function loadPromptTemplate(string $name): string
    {
        $path = $this->promptsDir . '/' . $name . '.prompt.md';

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Prompt template not found: %s', $path));
        }

        return file_get_contents($path);
    }

    /**
     * Replace placeholders in a template.
     *
     * @param array<string, string> $variables
     */
    protected function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    /**
     * Extract issues summary from analysis.
     */
    protected function extractIssuesSummary(Analysis $analysis): string
    {
        $issues = [];

        foreach ($analysis->getResults() as $result) {
            foreach ($result->getIssues() as $issue) {
                $severity = $issue['severity'] ?? 'unknown';
                $code = $issue['code'] ?? 'unknown';
                $evidence = $issue['evidence'] ?? '';

                $issues[] = sprintf('- [%s] %s: %s', strtoupper($severity), $code, $evidence);
            }
        }

        if (empty($issues)) {
            return 'No issues found.';
        }

        return implode("\n", $issues);
    }

    /**
     * Get the domain from analysis lead.
     */
    protected function getDomain(Analysis $analysis): string
    {
        return $analysis->getLead()?->getDomain() ?? 'unknown';
    }

    /**
     * Get the company name from analysis lead.
     */
    protected function getCompanyName(Analysis $analysis): string
    {
        return $analysis->getLead()?->getCompanyName()
            ?? $analysis->getLead()?->getCompany()?->getName()
            ?? $this->getDomain($analysis);
    }

    /**
     * Store content and return URL.
     */
    protected function storeContent(string $content, string $path, string $mimeType): string
    {
        $this->storage->upload($path, $content, $mimeType);

        return $this->storage->getUrl($path);
    }
}
