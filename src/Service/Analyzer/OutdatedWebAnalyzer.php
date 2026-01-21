<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class OutdatedWebAnalyzer extends AbstractLeadAnalyzer
{
    private const DEPRECATED_TAGS = [
        'font' => 'Zastaralý tag <font>',
        'center' => 'Zastaralý tag <center>',
        'marquee' => 'Zastaralý tag <marquee>',
        'blink' => 'Zastaralý tag <blink>',
        'frame' => 'Zastaralý tag <frame>',
        'frameset' => 'Zastaralý tag <frameset>',
        'noframes' => 'Zastaralý tag <noframes>',
        'applet' => 'Zastaralý tag <applet>',
        'basefont' => 'Zastaralý tag <basefont>',
        'big' => 'Zastaralý tag <big>',
        'strike' => 'Zastaralý tag <strike>',
        'tt' => 'Zastaralý tag <tt>',
        'acronym' => 'Zastaralý tag <acronym>',
    ];

    private const HTML5_SEMANTIC_ELEMENTS = [
        'header',
        'footer',
        'main',
        'nav',
        'section',
        'article',
        'aside',
    ];

    private const INLINE_STYLE_THRESHOLD = 10;
    private const TABLE_LAYOUT_THRESHOLD = 3;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::OUTDATED_CODE;
    }

    public function getPriority(): int
    {
        return 35;
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null || $result['content'] === null) {
            return AnalyzerResult::failure(
                $this->getCategory(),
                $result['error'] ?? 'Failed to fetch content'
            );
        }

        $content = $result['content'];
        $issues = [];
        $rawData = [
            'url' => $url,
            'deprecatedTags' => [],
            'hasHtml5Doctype' => false,
            'html5SemanticElements' => [],
            'missingSemanticElements' => [],
            'inlineStyleCount' => 0,
            'tableLayoutCount' => 0,
            'flashDetected' => false,
            'javaAppletDetected' => false,
            'fixedWidthElements' => 0,
            'outdatedScore' => 0,
        ];

        // Check DOCTYPE
        $doctypeResult = $this->checkDoctype($content);
        $rawData['hasHtml5Doctype'] = $doctypeResult['isHtml5'];
        $rawData['doctypeFound'] = $doctypeResult['doctype'];
        if (!$doctypeResult['isHtml5']) {
            $issues[] = $this->createDoctypeIssue($doctypeResult);
            $rawData['outdatedScore'] += 2;
        }

        // Check deprecated HTML tags
        $deprecatedResult = $this->checkDeprecatedTags($content);
        $rawData['deprecatedTags'] = $deprecatedResult['found'];
        if (!empty($deprecatedResult['found'])) {
            $issues[] = $this->createDeprecatedTagsIssue($deprecatedResult['found']);
            $rawData['outdatedScore'] += count($deprecatedResult['found']) * 2;
        }

        // Check HTML5 semantic elements
        $semanticResult = $this->checkSemanticElements($content);
        $rawData['html5SemanticElements'] = $semanticResult['found'];
        $rawData['missingSemanticElements'] = $semanticResult['missing'];
        if (empty($semanticResult['found']) && !empty($semanticResult['missing'])) {
            $issues[] = $this->createMissingSemanticIssue($semanticResult['missing']);
            $rawData['outdatedScore'] += 3;
        }

        // Check inline styles
        $inlineStyleCount = $this->countInlineStyles($content);
        $rawData['inlineStyleCount'] = $inlineStyleCount;
        if ($inlineStyleCount > self::INLINE_STYLE_THRESHOLD) {
            $issues[] = $this->createInlineStylesIssue($inlineStyleCount);
            $rawData['outdatedScore'] += 1;
        }

        // Check table-based layout
        $tableLayoutResult = $this->checkTableLayout($content);
        $rawData['tableLayoutCount'] = $tableLayoutResult['count'];
        $rawData['tableLayoutIndicators'] = $tableLayoutResult['indicators'];
        if ($tableLayoutResult['isTableLayout']) {
            $issues[] = $this->createTableLayoutIssue($tableLayoutResult);
            $rawData['outdatedScore'] += 5;
        }

        // Check for Flash content
        $flashResult = $this->checkFlashContent($content);
        $rawData['flashDetected'] = $flashResult['detected'];
        if ($flashResult['detected']) {
            $issues[] = $this->createFlashIssue($flashResult);
            $rawData['outdatedScore'] += 10;
        }

        // Check for Java applets
        $javaResult = $this->checkJavaApplets($content);
        $rawData['javaAppletDetected'] = $javaResult['detected'];
        if ($javaResult['detected']) {
            $issues[] = $this->createJavaAppletIssue();
            $rawData['outdatedScore'] += 10;
        }

        // Check fixed width elements
        $fixedWidthResult = $this->checkFixedWidthElements($content);
        $rawData['fixedWidthElements'] = $fixedWidthResult['count'];
        $rawData['fixedWidthExamples'] = $fixedWidthResult['examples'];
        if ($fixedWidthResult['count'] > 5) {
            $issues[] = $this->createFixedWidthIssue($fixedWidthResult);
            $rawData['outdatedScore'] += 2;
        }

        // Check for jQuery
        $jQueryResult = $this->checkJQuery($content);
        $rawData['jQueryDetected'] = $jQueryResult['detected'];
        if ($jQueryResult['detected']) {
            $issues[] = $this->createIssue('outdated_jquery', $jQueryResult['evidence']);
            $rawData['outdatedScore'] += 2;
        }

        // Check for blocking scripts
        $blockingScriptsResult = $this->checkBlockingScripts($content);
        $rawData['blockingScriptsCount'] = $blockingScriptsResult['count'];
        $rawData['blockingScriptsExamples'] = $blockingScriptsResult['examples'];
        if ($blockingScriptsResult['count'] > 0) {
            $issues[] = $this->createIssue('outdated_blocking_scripts', $blockingScriptsResult['evidence']);
            $rawData['outdatedScore'] += $blockingScriptsResult['count'];
        }

        // Determine if web is outdated based on score
        $rawData['isOutdated'] = $rawData['outdatedScore'] >= 5;
        $rawData['outdatedLevel'] = $this->calculateOutdatedLevel($rawData['outdatedScore']);

        $this->logger->info('Outdated web analysis completed', [
            'url' => $url,
            'isOutdated' => $rawData['isOutdated'],
            'outdatedScore' => $rawData['outdatedScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{isHtml5: bool, doctype: ?string}
     */
    private function checkDoctype(string $content): array
    {
        // Check for HTML5 doctype
        if (preg_match('/<!DOCTYPE\s+html\s*>/i', $content)) {
            return ['isHtml5' => true, 'doctype' => 'HTML5'];
        }

        // Check for other doctypes
        if (preg_match('/<!DOCTYPE[^>]*>/i', $content, $matches)) {
            $doctype = $matches[0];

            if (stripos($doctype, 'XHTML') !== false) {
                return ['isHtml5' => false, 'doctype' => 'XHTML'];
            }

            if (stripos($doctype, 'HTML 4') !== false) {
                return ['isHtml5' => false, 'doctype' => 'HTML 4'];
            }

            return ['isHtml5' => false, 'doctype' => 'Other'];
        }

        return ['isHtml5' => false, 'doctype' => null];
    }

    /**
     * @return array{found: array<string, int>}
     */
    private function checkDeprecatedTags(string $content): array
    {
        $found = [];

        foreach (self::DEPRECATED_TAGS as $tag => $description) {
            $pattern = '/<' . $tag . '[\s>]/i';
            if (preg_match_all($pattern, $content, $matches)) {
                $found[$tag] = count($matches[0]);
            }
        }

        return ['found' => $found];
    }

    /**
     * @return array{found: array<string>, missing: array<string>}
     */
    private function checkSemanticElements(string $content): array
    {
        $found = [];
        $missing = [];

        foreach (self::HTML5_SEMANTIC_ELEMENTS as $element) {
            $pattern = '/<' . $element . '[\s>]/i';
            if (preg_match($pattern, $content)) {
                $found[] = $element;
            } else {
                $missing[] = $element;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }

    private function countInlineStyles(string $content): int
    {
        preg_match_all('/\sstyle\s*=\s*["\'][^"\']+["\']/i', $content, $matches);

        return count($matches[0]);
    }

    /**
     * @return array{isTableLayout: bool, count: int, indicators: array<string>}
     */
    private function checkTableLayout(string $content): array
    {
        $indicators = [];
        $count = 0;

        // Count tables
        preg_match_all('/<table[\s>]/i', $content, $matches);
        $tableCount = count($matches[0]);

        // Check for layout indicators
        if (preg_match_all('/<table[^>]*(?:width|cellpadding|cellspacing|border)\s*=\s*["\']?\d+/i', $content, $matches)) {
            $indicators[] = 'Tables with layout attributes';
            $count += count($matches[0]);
        }

        // Nested tables (strong indicator of table layout)
        if (preg_match('/<table[^>]*>.*<table[^>]*>/is', $content)) {
            $indicators[] = 'Nested tables detected';
            $count += 3;
        }

        // Tables with no semantic role
        if (preg_match_all('/<table(?![^>]*role\s*=)/i', $content, $matches)) {
            if (count($matches[0]) > 2) {
                $indicators[] = 'Multiple tables without role attribute';
                $count++;
            }
        }

        // Check for spacer GIFs (classic table layout indicator)
        if (preg_match('/spacer\.gif|blank\.gif|1x1\.gif|pixel\.gif/i', $content)) {
            $indicators[] = 'Spacer GIF images detected';
            $count += 5;
        }

        // Tables used with percentage widths for layout
        if (preg_match_all('/<td[^>]*width\s*=\s*["\']?\d+%/i', $content, $matches)) {
            if (count($matches[0]) > 5) {
                $indicators[] = 'Table cells with percentage widths';
                $count++;
            }
        }

        $isTableLayout = $count >= self::TABLE_LAYOUT_THRESHOLD || ($tableCount > 3 && !empty($indicators));

        return [
            'isTableLayout' => $isTableLayout,
            'count' => $count,
            'indicators' => $indicators,
        ];
    }

    /**
     * @return array{detected: bool, types: array<string>}
     */
    private function checkFlashContent(string $content): array
    {
        $types = [];

        // Check for SWF files
        if (preg_match('/\.swf["\'\s>?]/i', $content)) {
            $types[] = 'SWF file';
        }

        // Check for Flash MIME type
        if (preg_match('/application\/x-shockwave-flash/i', $content)) {
            $types[] = 'Flash MIME type';
        }

        // Check for Flash embed
        if (preg_match('/<embed[^>]*flash/i', $content)) {
            $types[] = 'Flash embed';
        }

        // Check for Flash object
        if (preg_match('/<object[^>]*flash/i', $content)) {
            $types[] = 'Flash object';
        }

        return [
            'detected' => !empty($types),
            'types' => $types,
        ];
    }

    /**
     * @return array{detected: bool}
     */
    private function checkJavaApplets(string $content): array
    {
        $detected = false;

        // Check for applet tag
        if (preg_match('/<applet[\s>]/i', $content)) {
            $detected = true;
        }

        // Check for Java MIME type
        if (preg_match('/application\/x-java-applet/i', $content)) {
            $detected = true;
        }

        // Check for .class or .jar files
        if (preg_match('/\.(class|jar)["\'\s>?]/i', $content)) {
            $detected = true;
        }

        return ['detected' => $detected];
    }

    /**
     * @return array{count: int, examples: array<string>}
     */
    private function checkFixedWidthElements(string $content): array
    {
        $examples = [];

        // Check for fixed pixel widths in inline styles
        preg_match_all('/style\s*=\s*["\'][^"\']*width\s*:\s*(\d{3,})px/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if ((int) $match[1] > 500) {
                $examples[] = 'width: ' . $match[1] . 'px';
            }
        }

        // Check for width attributes on elements (not images)
        preg_match_all('/<(?!img|video|canvas|svg)[a-z]+[^>]*\swidth\s*=\s*["\']?(\d{3,})["\']?/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if ((int) $match[1] > 500) {
                $examples[] = 'width="' . $match[1] . '"';
            }
        }

        $examples = array_unique(array_slice($examples, 0, 5));

        return [
            'count' => count($examples),
            'examples' => $examples,
        ];
    }

    private function calculateOutdatedLevel(int $score): string
    {
        if ($score >= 15) {
            return 'critical';
        }

        if ($score >= 10) {
            return 'high';
        }

        if ($score >= 5) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Check for jQuery usage.
     *
     * @return array{detected: bool, evidence: ?string}
     */
    private function checkJQuery(string $content): array
    {
        $detected = false;
        $evidence = null;

        // Check for jQuery references
        $patterns = [
            '/jquery[.-]?\d+\.\d+(?:\.\d+)?(?:\.min)?\.js/i' => 'jQuery script file',
            '/jquery\.min\.js/i' => 'jQuery minified script',
            '/code\.jquery\.com/i' => 'jQuery CDN',
            '/cdnjs\.cloudflare\.com\/ajax\/libs\/jquery/i' => 'jQuery CDN (Cloudflare)',
            '/ajax\.googleapis\.com\/ajax\/libs\/jquery/i' => 'jQuery CDN (Google)',
        ];

        foreach ($patterns as $pattern => $description) {
            if (preg_match($pattern, $content, $matches)) {
                $detected = true;
                $evidence = $description . ': ' . $matches[0];
                break;
            }
        }

        // Check for jQuery global variable usage
        if (!$detected && preg_match('/\bjQuery\s*\(|\$\s*\(\s*["\']|\.ready\s*\(/i', $content)) {
            // Additional check to make sure it's really jQuery (not other $-using libs)
            if (preg_match('/jquery/i', $content)) {
                $detected = true;
                $evidence = 'jQuery code detected in page';
            }
        }

        return [
            'detected' => $detected,
            'evidence' => $evidence,
        ];
    }

    /**
     * Check for blocking scripts in <head>.
     *
     * @return array{count: int, examples: array<string>, evidence: ?string}
     */
    private function checkBlockingScripts(string $content): array
    {
        $count = 0;
        $examples = [];

        // Extract <head> content
        if (!preg_match('/<head[^>]*>(.*?)<\/head>/is', $content, $headMatch)) {
            return ['count' => 0, 'examples' => [], 'evidence' => null];
        }

        $headContent = $headMatch[1];

        // Find all <script src="..."> without async or defer
        preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $headContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fullTag = $match[0];
            $src = $match[1];

            // Skip if has async or defer
            if (preg_match('/\b(async|defer)\b/i', $fullTag)) {
                continue;
            }

            // Skip inline event handlers and tiny utility scripts
            if (preg_match('/google-analytics|gtag|gtm|analytics|pixel|tracking/i', $src)) {
                continue;
            }

            $count++;
            if (count($examples) < 3) {
                // Get just the filename from the URL
                $filename = basename(parse_url($src, PHP_URL_PATH) ?? $src);
                $examples[] = $filename;
            }
        }

        $evidence = null;
        if ($count > 0) {
            $evidence = sprintf(
                'Nalezeno %d blokujících skriptů v <head>: %s',
                $count,
                implode(', ', $examples)
            );
        }

        return [
            'count' => $count,
            'examples' => $examples,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array{isHtml5: bool, doctype: ?string} $result
     */
    private function createDoctypeIssue(array $result): Issue
    {
        if ($result['doctype'] === null) {
            return $this->createIssue('outdated_missing_doctype', 'DOCTYPE nebyl nalezen v HTML');
        }

        return $this->createIssue('outdated_old_doctype', 'Detekováno: ' . $result['doctype']);
    }

    /**
     * @param array<string, int> $found
     */
    private function createDeprecatedTagsIssue(array $found): Issue
    {
        $tagList = [];
        foreach ($found as $tag => $count) {
            $tagList[] = sprintf('<%s> (%dx)', $tag, $count);
        }

        return $this->createIssue('outdated_deprecated_tags', 'Nalezeno: ' . implode(', ', $tagList));
    }

    /**
     * @param array<string> $missing
     */
    private function createMissingSemanticIssue(array $missing): Issue
    {
        return $this->createIssue('outdated_no_semantic_html', 'Nepoužité elementy: <' . implode('>, <', array_slice($missing, 0, 4)) . '>');
    }

    private function createInlineStylesIssue(int $count): Issue
    {
        return $this->createIssue('outdated_excessive_inline_styles', sprintf('Nalezeno %d inline style atributů', $count));
    }

    /**
     * @param array{isTableLayout: bool, count: int, indicators: array<string>} $result
     */
    private function createTableLayoutIssue(array $result): Issue
    {
        return $this->createIssue('outdated_table_layout', 'Indikátory: ' . implode(', ', $result['indicators']));
    }

    /**
     * @param array{detected: bool, types: array<string>} $result
     */
    private function createFlashIssue(array $result): Issue
    {
        return $this->createIssue('outdated_flash_content', 'Detekováno: ' . implode(', ', $result['types']));
    }

    private function createJavaAppletIssue(): Issue
    {
        return $this->createIssue('outdated_java_applet', 'Detekován Java applet nebo .class/.jar soubor');
    }

    /**
     * @param array{count: int, examples: array<string>} $result
     */
    private function createFixedWidthIssue(array $result): Issue
    {
        return $this->createIssue('outdated_fixed_width', 'Příklady: ' . implode(', ', $result['examples']));
    }
}
