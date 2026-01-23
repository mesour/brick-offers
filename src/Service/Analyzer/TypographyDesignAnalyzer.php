<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Analyzer for typography and design consistency issues.
 * Checks for: typographic errors, font consistency, heading hierarchy, color usage.
 */
class TypographyDesignAnalyzer extends AbstractLeadAnalyzer
{
    // Czech typography patterns
    private const WRONG_QUOTES_PATTERN = '/["\'](?=[A-Za-zÁČĎÉĚÍŇÓŘŠŤÚŮÝŽáčďéěíňóřšťúůýž])/u';
    private const WRONG_DASH_PATTERN = '/\s-\s/'; // Should be – (en-dash) or — (em-dash)
    private const MULTIPLE_SPACES_PATTERN = '/  +/';
    private const ORPHAN_PREPOSITION_PATTERN = '/\s([ksvzuoiaKSVZUOIA])\s+(?=[A-Za-zÁČĎÉĚÍŇÓŘŠŤÚŮÝŽáčďéěíňóřšťúůýž])/u';

    // Color extraction pattern (hex, rgb, rgba, named colors)
    private const COLOR_PATTERNS = [
        'hex' => '/#(?:[0-9a-fA-F]{3}){1,2}\b/',
        'rgb' => '/rgb\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)/',
        'rgba' => '/rgba\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*[\d.]+\s*\)/',
    ];

    // Thresholds
    private const MAX_UNIQUE_COLORS = 12;
    private const MAX_FONT_FAMILIES = 4;
    private const MAX_FONT_SIZES_INLINE = 10;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::TYPOGRAPHY;
    }

    public function getPriority(): int
    {
        return 65; // Run with other design-related analyzers
    }

    /**
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return []; // Universal analyzer - runs for all industries
    }

    public function getDescription(): string
    {
        return 'Kontroluje typografické chyby, konzistenci designu, hierarchii nadpisů a barevné schéma.';
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $issues = [];
        $rawData = [
            'url' => $url,
            'checks' => [],
        ];

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null) {
            return AnalyzerResult::failure($this->getCategory(), 'Failed to fetch URL: ' . $result['error']);
        }

        $html = $result['content'] ?? '';
        $textContent = $this->extractTextContent($html);

        // Check typography errors
        $typographyResult = $this->checkTypographyErrors($textContent, $html);
        $rawData['checks']['typography'] = $typographyResult['data'];
        array_push($issues, ...$typographyResult['issues']);

        // Check heading hierarchy
        $headingResult = $this->checkHeadingHierarchy($html);
        $rawData['checks']['headings'] = $headingResult['data'];
        array_push($issues, ...$headingResult['issues']);

        // Check font consistency (inline styles)
        $fontResult = $this->checkFontConsistency($html);
        $rawData['checks']['fonts'] = $fontResult['data'];
        array_push($issues, ...$fontResult['issues']);

        // Check color consistency
        $colorResult = $this->checkColorConsistency($html);
        $rawData['checks']['colors'] = $colorResult['data'];
        array_push($issues, ...$colorResult['issues']);

        // Check spacing issues
        $spacingResult = $this->checkSpacingIssues($html);
        $rawData['checks']['spacing'] = $spacingResult['data'];
        array_push($issues, ...$spacingResult['issues']);

        // Check image alt texts
        $altResult = $this->checkImageAlts($html);
        $rawData['checks']['imageAlts'] = $altResult['data'];
        array_push($issues, ...$altResult['issues']);

        // Check for design anti-patterns
        $antiPatternResult = $this->checkDesignAntiPatterns($html);
        $rawData['checks']['antiPatterns'] = $antiPatternResult['data'];
        array_push($issues, ...$antiPatternResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Extract text content from HTML (strip tags).
     */
    private function extractTextContent(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // Strip remaining tags and decode entities
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Check for Czech typography errors.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTypographyErrors(string $textContent, string $html): array
    {
        $issues = [];
        $data = [
            'wrongQuotes' => 0,
            'wrongDashes' => 0,
            'multipleSpaces' => 0,
            'orphanPrepositions' => 0,
            'samples' => [],
        ];

        // Check for wrong quotes (should be „" in Czech)
        if (preg_match_all(self::WRONG_QUOTES_PATTERN, $textContent, $matches)) {
            $data['wrongQuotes'] = count($matches[0]);
        }

        // Check for wrong dashes (hyphen instead of en-dash/em-dash)
        if (preg_match_all(self::WRONG_DASH_PATTERN, $textContent, $matches)) {
            $data['wrongDashes'] = count($matches[0]);
            if (count($matches[0]) > 0) {
                $data['samples']['wrongDash'] = array_slice($matches[0], 0, 3);
            }
        }

        // Check for multiple consecutive spaces
        if (preg_match_all(self::MULTIPLE_SPACES_PATTERN, $textContent, $matches)) {
            $data['multipleSpaces'] = count($matches[0]);
        }

        // Check for orphan prepositions (k, s, v, z, u, o, i, a at end of line)
        if (preg_match_all(self::ORPHAN_PREPOSITION_PATTERN, $textContent, $matches)) {
            $data['orphanPrepositions'] = count($matches[0]);
        }

        // Create issues based on findings
        $totalErrors = $data['wrongQuotes'] + $data['wrongDashes'] + $data['multipleSpaces'];

        if ($data['wrongDashes'] > 3) {
            $issues[] = $this->createIssue(
                'typography_wrong_dashes',
                sprintf('Nalezeno %d případů použití spojovníku místo pomlčky', $data['wrongDashes'])
            );
        }

        if ($data['multipleSpaces'] > 5) {
            $issues[] = $this->createIssue(
                'typography_multiple_spaces',
                sprintf('Nalezeno %d případů vícenásobných mezer v textu', $data['multipleSpaces'])
            );
        }

        if ($data['wrongQuotes'] > 10) {
            $issues[] = $this->createIssue(
                'typography_wrong_quotes',
                sprintf('Nalezeno %d případů nesprávných uvozovek (měly by být „české")', $data['wrongQuotes'])
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check heading hierarchy (H1-H6).
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkHeadingHierarchy(string $html): array
    {
        $issues = [];
        $data = [
            'h1Count' => 0,
            'h2Count' => 0,
            'h3Count' => 0,
            'h4Count' => 0,
            'h5Count' => 0,
            'h6Count' => 0,
            'hierarchyValid' => true,
            'skippedLevels' => [],
        ];

        // Count headings
        for ($i = 1; $i <= 6; $i++) {
            if (preg_match_all('/<h' . $i . '[^>]*>/i', $html, $matches)) {
                $data['h' . $i . 'Count'] = count($matches[0]);
            }
        }

        // Check for multiple H1s
        if ($data['h1Count'] > 1) {
            $issues[] = $this->createIssue(
                'heading_multiple_h1',
                sprintf('Nalezeno %d H1 nadpisů (měl by být pouze jeden)', $data['h1Count'])
            );
        }

        // Check for missing H1
        if ($data['h1Count'] === 0) {
            $issues[] = $this->createIssue(
                'heading_no_h1',
                'Stránka nemá žádný H1 nadpis'
            );
        }

        // Check for skipped heading levels
        $headingOrder = [];
        if (preg_match_all('/<(h[1-6])[^>]*>/i', $html, $matches)) {
            $headingOrder = array_map(fn ($h) => (int) substr($h, 1), $matches[1]);
        }

        $lastLevel = 0;
        foreach ($headingOrder as $level) {
            if ($level > $lastLevel + 1 && $lastLevel > 0) {
                $data['hierarchyValid'] = false;
                $skipped = 'H' . ($lastLevel + 1);
                if (!in_array($skipped, $data['skippedLevels'], true)) {
                    $data['skippedLevels'][] = $skipped;
                }
            }
            $lastLevel = $level;
        }

        if (!$data['hierarchyValid']) {
            $issues[] = $this->createIssue(
                'heading_skipped_levels',
                sprintf('Přeskočené úrovně nadpisů: %s', implode(', ', $data['skippedLevels']))
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check font consistency from inline styles.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkFontConsistency(string $html): array
    {
        $issues = [];
        $data = [
            'fontFamilies' => [],
            'fontSizes' => [],
            'inlineStyleCount' => 0,
        ];

        // Extract font-family from inline styles
        if (preg_match_all('/font-family\s*:\s*([^;"\']+)/i', $html, $matches)) {
            $data['fontFamilies'] = array_unique(array_map('trim', $matches[1]));
        }

        // Extract font-size from inline styles
        if (preg_match_all('/font-size\s*:\s*(\d+(?:px|em|rem|pt|%)?)/i', $html, $matches)) {
            $data['fontSizes'] = array_unique($matches[1]);
        }

        // Count inline style attributes
        $data['inlineStyleCount'] = preg_match_all('/style\s*=\s*["\'][^"\']+["\']/', $html);

        // Check for too many font families
        if (count($data['fontFamilies']) > self::MAX_FONT_FAMILIES) {
            $issues[] = $this->createIssue(
                'design_too_many_fonts',
                sprintf(
                    'Použito %d různých fontů (doporučeno max %d)',
                    count($data['fontFamilies']),
                    self::MAX_FONT_FAMILIES
                )
            );
        }

        // Check for too many inline font sizes
        if (count($data['fontSizes']) > self::MAX_FONT_SIZES_INLINE) {
            $issues[] = $this->createIssue(
                'design_inconsistent_font_sizes',
                sprintf(
                    'Nalezeno %d různých velikostí písma v inline stylech',
                    count($data['fontSizes'])
                )
            );
        }

        // Excessive inline styles
        if ($data['inlineStyleCount'] > 50) {
            $issues[] = $this->createIssue(
                'design_excessive_inline_styles',
                sprintf('Nadměrné použití inline stylů (%d)', $data['inlineStyleCount'])
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check color consistency.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkColorConsistency(string $html): array
    {
        $issues = [];
        $data = [
            'uniqueColors' => [],
            'colorCount' => 0,
        ];

        $colors = [];

        // Extract colors from inline styles and CSS
        foreach (self::COLOR_PATTERNS as $type => $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                $colors = array_merge($colors, $matches[0]);
            }
        }

        // Normalize and deduplicate
        $normalizedColors = array_unique(array_map(fn ($c) => strtolower($c), $colors));
        $data['uniqueColors'] = array_values($normalizedColors);
        $data['colorCount'] = count($normalizedColors);

        if ($data['colorCount'] > self::MAX_UNIQUE_COLORS) {
            $issues[] = $this->createIssue(
                'design_too_many_colors',
                sprintf(
                    'Použito %d různých barev (doporučeno max %d pro konzistentní design)',
                    $data['colorCount'],
                    self::MAX_UNIQUE_COLORS
                )
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for spacing issues.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkSpacingIssues(string $html): array
    {
        $issues = [];
        $data = [
            'brTagCount' => 0,
            'nbspCount' => 0,
            'emptyParagraphs' => 0,
        ];

        // Count <br> tags (often misused for spacing)
        $data['brTagCount'] = preg_match_all('/<br\s*\/?>/i', $html);

        // Count &nbsp; (often misused)
        $data['nbspCount'] = preg_match_all('/&nbsp;/', $html);

        // Count empty paragraphs
        $data['emptyParagraphs'] = preg_match_all('/<p[^>]*>\s*<\/p>/i', $html);

        // Excessive <br> usage
        if ($data['brTagCount'] > 20) {
            $issues[] = $this->createIssue(
                'design_excessive_br_tags',
                sprintf('Nadměrné použití <br> tagů (%d) - mělo by se řešit CSS marginy', $data['brTagCount'])
            );
        }

        // Excessive &nbsp; usage
        if ($data['nbspCount'] > 30) {
            $issues[] = $this->createIssue(
                'design_excessive_nbsp',
                sprintf('Nadměrné použití &nbsp; (%d) - spacing by měl být řešen CSS', $data['nbspCount'])
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check image alt texts.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkImageAlts(string $html): array
    {
        $issues = [];
        $data = [
            'totalImages' => 0,
            'missingAlts' => 0,
            'emptyAlts' => 0,
            'genericAlts' => 0,
        ];

        // Find all images
        if (preg_match_all('/<img[^>]*>/i', $html, $imgMatches)) {
            $data['totalImages'] = count($imgMatches[0]);

            foreach ($imgMatches[0] as $img) {
                // Check for missing alt
                if (!preg_match('/alt\s*=/i', $img)) {
                    $data['missingAlts']++;
                    continue;
                }

                // Check for empty alt
                if (preg_match('/alt\s*=\s*["\']?\s*["\']?/i', $img) && !preg_match('/alt\s*=\s*["\'][^"\']+["\']/i', $img)) {
                    $data['emptyAlts']++;
                    continue;
                }

                // Check for generic alt texts
                if (preg_match('/alt\s*=\s*["\'](?:image|obrázek|foto|picture|img|banner|\d+)["\']?/i', $img)) {
                    $data['genericAlts']++;
                }
            }
        }

        $problematicAlts = $data['missingAlts'] + $data['emptyAlts'] + $data['genericAlts'];

        if ($data['totalImages'] > 0 && $problematicAlts > $data['totalImages'] * 0.3) {
            $issues[] = $this->createIssue(
                'design_poor_image_alts',
                sprintf(
                    'Problematické alt texty u obrázků: %d chybějících, %d prázdných, %d generických z %d celkem',
                    $data['missingAlts'],
                    $data['emptyAlts'],
                    $data['genericAlts'],
                    $data['totalImages']
                )
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for common design anti-patterns.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkDesignAntiPatterns(string $html): array
    {
        $issues = [];
        $data = [
            'hasMarquee' => false,
            'hasBlink' => false,
            'hasTableLayout' => false,
            'hasAutoplayMedia' => false,
            'hasPopupScript' => false,
        ];

        // Check for deprecated/bad elements
        $data['hasMarquee'] = (bool) preg_match('/<marquee/i', $html);
        $data['hasBlink'] = (bool) preg_match('/<blink/i', $html);

        // Check for table-based layout
        $tableCount = preg_match_all('/<table/i', $html);
        $data['hasTableLayout'] = $tableCount > 3;

        // Check for autoplay media
        $data['hasAutoplayMedia'] = (bool) preg_match('/<(?:video|audio)[^>]*autoplay/i', $html);

        // Check for popup scripts
        $data['hasPopupScript'] = (bool) preg_match('/(?:window\.open|alert\s*\(|confirm\s*\()/i', $html);

        if ($data['hasMarquee'] || $data['hasBlink']) {
            $issues[] = $this->createIssue(
                'design_deprecated_elements',
                'Použití zastaralých HTML elementů (marquee/blink)'
            );
        }

        if ($data['hasTableLayout']) {
            $issues[] = $this->createIssue(
                'design_table_layout',
                'Pravděpodobné použití tabulek pro layout stránky'
            );
        }

        if ($data['hasAutoplayMedia']) {
            $issues[] = $this->createIssue(
                'design_autoplay_media',
                'Automatické přehrávání médií může obtěžovat uživatele'
            );
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
