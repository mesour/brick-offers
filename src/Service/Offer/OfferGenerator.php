<?php

declare(strict_types=1);

namespace App\Service\Offer;

use App\Entity\EmailTemplate;
use App\Entity\Offer;
use App\Entity\User;
use App\Entity\UserEmailTemplate;
use App\Enum\Industry;
use App\Repository\EmailTemplateRepository;
use App\Repository\UserEmailTemplateRepository;
use App\Service\AI\ClaudeService;
use Psr\Log\LoggerInterface;

/**
 * Service for generating offer email content.
 *
 * Combines template rendering with AI personalization.
 */
class OfferGenerator
{
    private const DEFAULT_PERSONALIZATION_PROMPT = <<<'PROMPT'
Personalizuj oslovující email pro českého klienta. Uprav email tak, aby byl osobnější a poutavější, ale zachovej hlavní sdělení.
VŠECHNY TEXTY MUSÍ BÝT V ČEŠTINĚ.

Pravidla:
- Zachovej stejnou strukturu a klíčové informace
- Přidej relevantní personalizaci na základě firmy a nalezených problémů webu
- Udržuj profesionální tón
- Nepřidávej nepravdivá tvrzení ani sliby
- Zachovej email stručný a věcný

Informace o firmě:
- Doména: {{domain}}
- Firma: {{company_name}}
- Odvětví: {{industry}}
- Skóre webu: {{total_score}}/100
- Nalezené problémy: {{issues_count}}

Původní email:
{{original_content}}

Vrať pouze personalizované tělo emailu (HTML), bez jakéhokoliv vysvětlení.
PROMPT;

    public function __construct(
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly UserEmailTemplateRepository $userTemplateRepository,
        private readonly ClaudeService $claude,
        private readonly LoggerInterface $logger,
        private readonly string $trackingBaseUrl = '',
    ) {
    }

    /**
     * Generate offer content from template with optional AI personalization.
     *
     * @param array<string, mixed> $options
     */
    public function generate(Offer $offer, array $options = []): OfferContent
    {
        $this->logger->info('Generating offer content', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'lead_domain' => $offer->getLead()->getDomain(),
        ]);

        try {
            // Find the best template
            $template = $this->findTemplate(
                $offer->getUser(),
                $offer->getLead()->getIndustry(),
                $options['template_name'] ?? null,
            );

            if ($template === null) {
                return OfferContent::error('No template found for this user/industry combination');
            }

            // Build template variables
            $variables = $this->buildVariables($offer);

            // Render template
            $subject = $this->renderTemplate($template, 'subject', $variables);
            $body = $this->renderTemplate($template, 'body', $variables);

            $aiMetadata = [];

            // Apply AI personalization if enabled
            $aiEnabled = $template instanceof UserEmailTemplate
                ? $template->isAiPersonalizationEnabled()
                : ($options['ai_personalization'] ?? true);

            if ($aiEnabled && !($options['skip_ai'] ?? false)) {
                $personalizationResult = $this->personalizeWithAi($body, $offer, $template);

                if ($personalizationResult !== null) {
                    $body = $personalizationResult['body'];
                    $aiMetadata = $personalizationResult['metadata'];
                }
            }

            // Generate plain text version
            $plainTextBody = $this->generatePlainText($body);

            $this->logger->info('Offer content generated successfully', [
                'offer_id' => $offer->getId()?->toRfc4122(),
                'ai_personalized' => !empty($aiMetadata),
            ]);

            return OfferContent::success(
                subject: $subject,
                body: $body,
                plainTextBody: $plainTextBody,
                aiMetadata: $aiMetadata,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Offer content generation failed', [
                'offer_id' => $offer->getId()?->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return OfferContent::error($e->getMessage());
        }
    }

    /**
     * Find the best template for user and industry.
     */
    private function findTemplate(
        User $user,
        ?Industry $industry,
        ?string $templateName = null,
    ): EmailTemplate|UserEmailTemplate|null {
        // First try user's custom templates
        $userTemplate = $this->userTemplateRepository->findBestMatch($user, $industry, $templateName);

        if ($userTemplate !== null) {
            return $userTemplate;
        }

        // Fall back to global templates
        return $this->emailTemplateRepository->findBestMatch($user, $industry, $templateName);
    }

    /**
     * Build template variables from offer data.
     *
     * @return array<string, string>
     */
    private function buildVariables(Offer $offer): array
    {
        $lead = $offer->getLead();
        $analysis = $offer->getAnalysis() ?? $lead->getLatestAnalysis();
        $proposal = $offer->getProposal();
        $user = $offer->getUser();

        $variables = [
            // Lead data
            'domain' => $lead->getDomain() ?? '',
            'company_name' => $lead->getCompanyName() ?? $lead->getCompany()?->getName() ?? $lead->getDomain() ?? '',
            'contact_name' => $offer->getRecipientName() ?? '',
            'email' => $offer->getRecipientEmail(),
            'phone' => $lead->getPhone() ?? '',

            // Analysis data
            'total_score' => '0',
            'issues_count' => '0',
            'critical_issues_count' => '0',
            'top_issues' => '',
            'industry' => $lead->getIndustry()?->getLabel() ?? 'General',

            // Proposal data
            'proposal_title' => '',
            'proposal_summary' => '',
            'proposal_link' => '',

            // Tracking
            'tracking_pixel' => $this->getTrackingPixel($offer),
            'unsubscribe_link' => $this->getUnsubscribeLink($offer),

            // User/sender data
            'sender_name' => $user->getName(),
            'sender_email' => $user->getEmail() ?? '',
            'sender_signature' => $user->getSetting('email_preferences.signature', ''),
        ];

        // Add analysis data if available
        if ($analysis !== null) {
            $variables['total_score'] = (string) $analysis->getTotalScore();
            $variables['issues_count'] = (string) $analysis->getIssueCount();
            $variables['critical_issues_count'] = (string) $analysis->getCriticalIssueCount();
            $variables['top_issues'] = $this->formatTopIssues($analysis);
        }

        // Add proposal data if available
        if ($proposal !== null) {
            $variables['proposal_title'] = $proposal->getTitle();
            $variables['proposal_summary'] = $proposal->getSummary() ?? '';
            $variables['proposal_link'] = $this->getProposalLink($proposal);
        }

        return $variables;
    }

    /**
     * Render template subject or body.
     *
     * @param array<string, string> $variables
     */
    private function renderTemplate(
        EmailTemplate|UserEmailTemplate $template,
        string $type,
        array $variables,
    ): string {
        $content = $type === 'subject'
            ? $template->getSubjectTemplate()
            : $template->getBodyTemplate();

        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        return $content;
    }

    /**
     * Apply AI personalization to the email body.
     *
     * @return array{body: string, metadata: array<string, mixed>}|null
     */
    private function personalizeWithAi(
        string $body,
        Offer $offer,
        EmailTemplate|UserEmailTemplate $template,
    ): ?array {
        // Get custom AI prompt or use default
        $prompt = $template instanceof UserEmailTemplate
            ? ($template->getAiPersonalizationPrompt() ?? self::DEFAULT_PERSONALIZATION_PROMPT)
            : self::DEFAULT_PERSONALIZATION_PROMPT;

        $lead = $offer->getLead();
        $analysis = $offer->getAnalysis() ?? $lead->getLatestAnalysis();

        // Build prompt variables
        $promptVariables = [
            'domain' => $lead->getDomain() ?? '',
            'company_name' => $lead->getCompanyName() ?? $lead->getCompany()?->getName() ?? '',
            'industry' => $lead->getIndustry()?->getLabel() ?? 'General',
            'total_score' => (string) ($analysis?->getTotalScore() ?? 0),
            'issues_count' => (string) ($analysis?->getIssueCount() ?? 0),
            'original_content' => $body,
        ];

        foreach ($promptVariables as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
        }

        try {
            $response = $this->claude->generate($prompt, [
                'max_tokens' => 2000,
            ]);

            if (!$response->success) {
                $this->logger->warning('AI personalization failed', [
                    'error' => $response->error,
                ]);

                return null;
            }

            return [
                'body' => $response->content,
                'metadata' => [
                    'model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'personalization_applied' => true,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('AI personalization failed with exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate plain text version from HTML.
     */
    private function generatePlainText(string $html): string
    {
        // Simple HTML to plain text conversion
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Format top issues for template.
     */
    private function formatTopIssues(\App\Entity\Analysis $analysis): string
    {
        $issues = [];

        foreach ($analysis->getResults() as $result) {
            foreach ($result->getIssues() as $issue) {
                $issues[] = [
                    'severity' => $issue['severity'] ?? 'low',
                    'code' => $issue['code'] ?? 'unknown',
                    'evidence' => $issue['evidence'] ?? '',
                ];
            }
        }

        // Sort by severity (critical first)
        usort($issues, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

            return ($severityOrder[$a['severity']] ?? 4) <=> ($severityOrder[$b['severity']] ?? 4);
        });

        // Take top 3
        $topIssues = array_slice($issues, 0, 3);

        $formatted = [];
        foreach ($topIssues as $issue) {
            $formatted[] = sprintf('- [%s] %s', strtoupper($issue['severity']), $issue['code']);
        }

        return implode("\n", $formatted);
    }

    private function getTrackingPixel(Offer $offer): string
    {
        if (empty($this->trackingBaseUrl)) {
            return '';
        }

        $url = rtrim($this->trackingBaseUrl, '/') . '/api/track/open/' . $offer->getTrackingToken();

        return sprintf('<img src="%s" width="1" height="1" alt="" style="display:none;" />', htmlspecialchars($url));
    }

    private function getUnsubscribeLink(Offer $offer): string
    {
        if (empty($this->trackingBaseUrl)) {
            return '#';
        }

        return rtrim($this->trackingBaseUrl, '/') . '/unsubscribe/' . $offer->getTrackingToken();
    }

    private function getProposalLink(\App\Entity\Proposal $proposal): string
    {
        if (empty($this->trackingBaseUrl)) {
            return '#';
        }

        return rtrim($this->trackingBaseUrl, '/') . '/proposal/' . $proposal->getId()?->toRfc4122();
    }
}
