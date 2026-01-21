<?php

declare(strict_types=1);

namespace App\Service\Proposal;

use App\Entity\Analysis;
use App\Enum\Industry;
use App\Enum\ProposalType;
use App\Service\AI\ClaudeService;
use App\Service\Screenshot\ScreenshotService;
use App\Service\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Generates design mockup proposals for web design industry.
 */
#[AutoconfigureTag('app.proposal_generator')]
class DesignProposalGenerator extends AbstractProposalGenerator
{
    public function __construct(
        ClaudeService $claude,
        StorageInterface $storage,
        LoggerInterface $logger,
        private readonly ScreenshotService $screenshotService,
        string $promptsDir = '',
    ) {
        parent::__construct($claude, $storage, $logger, $promptsDir);
    }

    public function supports(Industry $industry): bool
    {
        return $industry === Industry::WEBDESIGN;
    }

    public function getProposalType(): ProposalType
    {
        return ProposalType::DESIGN_MOCKUP;
    }

    public function getName(): string
    {
        return 'design_proposal';
    }

    public function generate(Analysis $analysis, array $options = []): ProposalResult
    {
        $this->logger->info('Generating design proposal', [
            'analysis_id' => $analysis->getId()?->toRfc4122(),
            'domain' => $this->getDomain($analysis),
        ]);

        try {
            // Build and execute the prompt
            $prompt = $this->buildPrompt($analysis);
            $response = $this->claude->generate($prompt, $options);

            if (!$response->success) {
                return ProposalResult::error($response->error ?? 'Generation failed');
            }

            // Process the response
            return $this->processResponse($response->content, $analysis, $response->toAiMetadata());
        } catch (\Throwable $e) {
            $this->logger->error('Design proposal generation failed', [
                'error' => $e->getMessage(),
                'analysis_id' => $analysis->getId()?->toRfc4122(),
            ]);

            return ProposalResult::error($e->getMessage());
        }
    }

    protected function buildPrompt(Analysis $analysis): string
    {
        $domain = $this->getDomain($analysis);
        $companyName = $this->getCompanyName($analysis);
        $issuesSummary = $this->extractIssuesSummary($analysis);
        $score = $analysis->getTotalScore();
        $industry = $analysis->getIndustry()?->value ?? 'general';

        // Try to load template, fallback to inline prompt
        try {
            $template = $this->loadPromptTemplate('design_mockup');

            return $this->replaceVariables($template, [
                'domain' => $domain,
                'company_name' => $companyName,
                'issues_summary' => $issuesSummary,
                'total_score' => (string) $score,
                'industry' => $industry,
            ]);
        } catch (\RuntimeException) {
            // Fallback to inline prompt
            return $this->buildInlinePrompt($domain, $companyName, $issuesSummary, $score, $industry);
        }
    }

    private function buildInlinePrompt(
        string $domain,
        string $companyName,
        string $issuesSummary,
        int $score,
        string $industry
    ): string {
        return <<<PROMPT
You are a professional web designer creating a modern website redesign proposal.

## Website Information
- Domain: {$domain}
- Company: {$companyName}
- Industry: {$industry}
- Current Score: {$score}/100

## Current Issues Found
{$issuesSummary}

## Your Task
Create a modern, responsive HTML mockup that addresses the issues above. The design should:
1. Be mobile-first and fully responsive
2. Follow modern web design best practices
3. Include proper semantic HTML
4. Have clean, modern CSS
5. Address the accessibility issues found
6. Improve the overall user experience

## Output Format
Provide your response in the following JSON format:
```json
{
    "title": "Modern Redesign for {company}",
    "summary": "Brief 2-3 sentence summary of the redesign approach",
    "html": "<!DOCTYPE html>... complete HTML with embedded CSS ..."
}
```

Important:
- The HTML must be complete and self-contained (CSS embedded in <style> tags)
- Use modern CSS (flexbox, grid, CSS variables)
- Include responsive breakpoints
- Use a professional color scheme
- Keep the design clean and modern
PROMPT;
    }

    /**
     * @param array<string, mixed> $aiMetadata
     */
    protected function processResponse(string $response, Analysis $analysis, array $aiMetadata = []): ProposalResult
    {
        // Try to parse JSON response
        $data = $this->parseJsonResponse($response);

        if ($data === null) {
            // Fallback: treat entire response as HTML
            $this->logger->warning('Could not parse JSON response, using raw content');
            $data = [
                'title' => sprintf('Design Proposal for %s', $this->getDomain($analysis)),
                'summary' => 'Modern redesign addressing identified issues.',
                'html' => $response,
            ];
        }

        $title = $data['title'] ?? sprintf('Design Proposal for %s', $this->getDomain($analysis));
        $summary = $data['summary'] ?? null;
        $html = $data['html'] ?? $response;

        $outputs = [];

        // Store HTML
        $htmlPath = sprintf('proposals/%s/design.html', $analysis->getId()?->toRfc4122() ?? uniqid());
        $outputs['html'] = $this->storeContent($html, $htmlPath, 'text/html');

        // Generate screenshot if service is available
        if ($this->screenshotService->isAvailable()) {
            try {
                $screenshot = $this->screenshotService->captureFromHtml($html, [
                    'width' => 1920,
                    'height' => 1080,
                    'fullPage' => true,
                ]);

                $screenshotPath = sprintf('proposals/%s/screenshot.png', $analysis->getId()?->toRfc4122() ?? uniqid());
                $outputs['screenshot'] = $this->storeContent($screenshot, $screenshotPath, 'image/png');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to generate screenshot', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProposalResult(
            title: $title,
            content: $html,
            summary: $summary,
            outputs: $outputs,
            aiMetadata: $aiMetadata,
            success: true,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonResponse(string $response): ?array
    {
        // Try to find JSON in the response
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } else {
            $json = $response;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
