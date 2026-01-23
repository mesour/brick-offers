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
Jsi profesionální webový designér, který vytváří návrh redesignu webu pro českého klienta.
VŠECHNY TEXTY MUSÍ BÝT V ČEŠTINĚ.

## Informace o webu
- Doména: {$domain}
- Firma: {$companyName}
- Odvětví: {$industry}
- Aktuální skóre: {$score}/100

## Nalezené problémy
{$issuesSummary}

## Tvůj úkol
Vytvoř moderní, responzivní HTML mockup, který řeší výše uvedené problémy. Design by měl:
1. Být mobile-first a plně responzivní
2. Dodržovat moderní webdesign best practices
3. Obsahovat správné sémantické HTML
4. Mít čistý, moderní CSS
5. Řešit nalezené problémy s přístupností
6. Zlepšit celkový uživatelský zážitek

## Formát výstupu
Odpověz v následujícím JSON formátu:
```json
{
    "title": "Moderní redesign pro {company}",
    "summary": "Stručné shrnutí přístupu k redesignu (2-3 věty česky)",
    "html": "<!DOCTYPE html>... kompletní HTML s vloženým CSS ..."
}
```

DŮLEŽITÉ:
- HTML musí být kompletní a samostatné (CSS vložené v <style> tazích)
- Použij moderní CSS (flexbox, grid, CSS proměnné)
- Zahrň responzivní breakpointy
- Použij profesionální barevné schéma
- Zachovej čistý a moderní design
- VŠECHNY TEXTY V HTML MUSÍ BÝT ČESKY (navigace, tlačítka, nadpisy, obsah)
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
