<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes content quality - images, text, placeholders.
 */
class ContentQualityAnalyzer extends AbstractLeadAnalyzer
{
    // Placeholder text patterns
    private const PLACEHOLDER_PATTERNS = [
        '/lorem\s+ipsum/i',
        '/dolor\s+sit\s+amet/i',
        '/consectetur\s+adipiscing/i',
        '/váš\s+text\s*(zde|tady)/i',
        '/your\s+text\s*here/i',
        '/vložte\s+(svůj\s+)?text/i',
        '/insert\s+(your\s+)?text/i',
        '/sample\s+text/i',
        '/placeholder/i',
        '/coming\s+soon/i',
        '/under\s+construction/i',
        '/ve\s+výstavbě/i',
        '/připravujeme/i',
        '/\[text\]/i',
        '/\[název\]/i',
        '/\[popis\]/i',
    ];

    // Minimum acceptable image dimensions
    private const MIN_IMAGE_WIDTH = 200;
    private const MIN_IMAGE_HEIGHT = 150;

    // Small image threshold for warnings
    private const SMALL_IMAGE_THRESHOLD = 3;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::CONTENT_QUALITY;
    }

    public function getPriority(): int
    {
        return 70;
    }

    public function getDescription(): string
    {
        return 'Analyzuje kvalitu obsahu - obrázky, texty, placeholdery.';
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
            'hasPlaceholderText' => false,
            'placeholderExamples' => [],
            'smallImages' => 0,
            'brokenImages' => 0,
            'totalImages' => 0,
            'emptySections' => 0,
            'copyrightYear' => null,
            'contentScore' => 100,
        ];

        // Check for placeholder text
        $placeholderCheck = $this->checkPlaceholderText($content);
        if ($placeholderCheck['found']) {
            $rawData['hasPlaceholderText'] = true;
            $rawData['placeholderExamples'] = $placeholderCheck['examples'];
            $rawData['contentScore'] -= 30;
            $issues[] = $this->createIssue(
                'content_placeholder_text',
                'Nalezeno: ' . implode(', ', array_slice($placeholderCheck['examples'], 0, 3))
            );
        }

        // Check images
        $imageCheck = $this->checkImages($content);
        $rawData['totalImages'] = $imageCheck['total'];
        $rawData['smallImages'] = $imageCheck['small'];
        $rawData['imagesWithoutDimensions'] = $imageCheck['noDimensions'];

        if ($imageCheck['small'] >= self::SMALL_IMAGE_THRESHOLD) {
            $rawData['contentScore'] -= 15;
            $issues[] = $this->createIssue(
                'content_small_images',
                sprintf('Nalezeno %d malých obrázků z celkem %d', $imageCheck['small'], $imageCheck['total'])
            );
        }

        // Check for broken image references (common patterns)
        $brokenCheck = $this->checkBrokenImagePatterns($content);
        if ($brokenCheck['count'] > 0) {
            $rawData['brokenImages'] = $brokenCheck['count'];
            $rawData['brokenImageExamples'] = $brokenCheck['examples'];
            $rawData['contentScore'] -= 25;
            $issues[] = $this->createIssue(
                'content_broken_images',
                sprintf('Nalezeno %d potenciálně rozbitých obrázků', $brokenCheck['count'])
            );
        }

        // Check empty sections
        $emptyCheck = $this->checkEmptySections($content);
        if ($emptyCheck['count'] > 0) {
            $rawData['emptySections'] = $emptyCheck['count'];
            $rawData['contentScore'] -= 10;
            $issues[] = $this->createIssue(
                'content_empty_sections',
                sprintf('Nalezeno %d prázdných sekcí', $emptyCheck['count'])
            );
        }

        // Check copyright year
        $copyrightCheck = $this->checkCopyrightYear($content);
        if ($copyrightCheck['year'] !== null) {
            $rawData['copyrightYear'] = $copyrightCheck['year'];
            if ($copyrightCheck['isOutdated']) {
                $rawData['contentScore'] -= 5;
                $issues[] = $this->createIssue(
                    'content_outdated_copyright',
                    sprintf('Copyright rok: %d (aktuální: %d)', $copyrightCheck['year'], (int) date('Y'))
                );
            }
        }

        // Check for generic stock photo patterns
        $stockCheck = $this->checkStockPhotoPatterns($content);
        if ($stockCheck['likely']) {
            $rawData['hasStockPhotos'] = true;
            $rawData['stockPhotoIndicators'] = $stockCheck['indicators'];
            $rawData['contentScore'] -= 5;
            $issues[] = $this->createIssue(
                'content_generic_stock_photos',
                'Indikátory: ' . implode(', ', $stockCheck['indicators'])
            );
        }

        $rawData['contentLevel'] = $this->calculateContentLevel($rawData['contentScore']);

        $this->logger->info('Content quality analysis completed', [
            'url' => $url,
            'contentScore' => $rawData['contentScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{found: bool, examples: array<string>}
     */
    private function checkPlaceholderText(string $content): array
    {
        $examples = [];

        // Extract visible text (strip tags but keep content)
        $textContent = strip_tags($content);

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $textContent, $matches)) {
                $examples[] = trim($matches[0]);
            }
        }

        return [
            'found' => !empty($examples),
            'examples' => array_unique($examples),
        ];
    }

    /**
     * @return array{total: int, small: int, noDimensions: int}
     */
    private function checkImages(string $content): array
    {
        $total = 0;
        $small = 0;
        $noDimensions = 0;

        // Find all img tags
        preg_match_all('/<img[^>]+>/i', $content, $matches);

        foreach ($matches[0] as $imgTag) {
            $total++;

            // Extract width and height
            $width = null;
            $height = null;

            if (preg_match('/width\s*=\s*["\']?(\d+)/i', $imgTag, $m)) {
                $width = (int) $m[1];
            }
            if (preg_match('/height\s*=\s*["\']?(\d+)/i', $imgTag, $m)) {
                $height = (int) $m[1];
            }

            // Check inline style for dimensions
            if (preg_match('/style\s*=\s*["\'][^"\']*width\s*:\s*(\d+)px/i', $imgTag, $m)) {
                $width = (int) $m[1];
            }
            if (preg_match('/style\s*=\s*["\'][^"\']*height\s*:\s*(\d+)px/i', $imgTag, $m)) {
                $height = (int) $m[1];
            }

            if ($width === null && $height === null) {
                $noDimensions++;
            } elseif ($width !== null && $width < self::MIN_IMAGE_WIDTH) {
                $small++;
            } elseif ($height !== null && $height < self::MIN_IMAGE_HEIGHT) {
                $small++;
            }
        }

        return [
            'total' => $total,
            'small' => $small,
            'noDimensions' => $noDimensions,
        ];
    }

    /**
     * @return array{count: int, examples: array<string>}
     */
    private function checkBrokenImagePatterns(string $content): array
    {
        $examples = [];

        // Common broken image patterns
        $patterns = [
            '/src\s*=\s*["\'](?:about:blank|javascript:|data:,)["\']/' => 'Empty src',
            '/src\s*=\s*["\']["\']/' => 'Empty src attribute',
            '/<img[^>]+alt\s*=\s*["\'](?:image|obrázek|img|photo|placeholder)["\'][^>]*>/i' => 'Generic alt text',
            '/src\s*=\s*["\'].*(?:no-?image|placeholder|default)[^"\']*["\']/' => 'Placeholder image',
        ];

        foreach ($patterns as $pattern => $description) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $examples[] = $description;
                }
            }
        }

        return [
            'count' => count($examples),
            'examples' => array_unique(array_slice($examples, 0, 5)),
        ];
    }

    /**
     * @return array{count: int}
     */
    private function checkEmptySections(string $content): array
    {
        $count = 0;

        // Check for empty divs with class containing 'section' or 'content'
        $sectionPatterns = [
            '/<section[^>]*>\s*<\/section>/i',
            '/<div[^>]*class\s*=\s*["\'][^"\']*(?:section|content|wrapper)[^"\']*["\'][^>]*>\s*<\/div>/i',
            '/<article[^>]*>\s*<\/article>/i',
        ];

        foreach ($sectionPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            $count += count($matches[0]);
        }

        return ['count' => $count];
    }

    /**
     * @return array{year: ?int, isOutdated: bool}
     */
    private function checkCopyrightYear(string $content): array
    {
        $currentYear = (int) date('Y');

        // Look for copyright patterns
        $patterns = [
            '/(?:©|&copy;|copyright)\s*(\d{4})/i',
            '/(\d{4})\s*(?:©|&copy;)/i',
            '/all\s+rights\s+reserved\s*(\d{4})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $year = (int) $matches[1];
                // Only consider valid years (not in the future, not too old)
                if ($year >= 2000 && $year <= $currentYear) {
                    return [
                        'year' => $year,
                        'isOutdated' => $year < $currentYear - 1, // Outdated if more than 1 year old
                    ];
                }
            }
        }

        return ['year' => null, 'isOutdated' => false];
    }

    /**
     * @return array{likely: bool, indicators: array<string>}
     */
    private function checkStockPhotoPatterns(string $content): array
    {
        $indicators = [];

        // Common stock photo sources in URLs
        $stockSources = [
            'shutterstock' => 'Shutterstock',
            'istockphoto' => 'iStock',
            'gettyimages' => 'Getty Images',
            'unsplash' => 'Unsplash',
            'pexels' => 'Pexels',
            'pixabay' => 'Pixabay',
            'dreamstime' => 'Dreamstime',
            'depositphotos' => 'Depositphotos',
            'fotolia' => 'Fotolia',
        ];

        foreach ($stockSources as $pattern => $name) {
            if (stripos($content, $pattern) !== false) {
                $indicators[] = $name;
            }
        }

        // Common generic stock photo alt texts
        $genericAlts = [
            'business meeting',
            'handshake',
            'team work',
            'happy customer',
            'office',
            'success',
            'growth',
            'professional',
        ];

        foreach ($genericAlts as $alt) {
            if (preg_match('/alt\s*=\s*["\'][^"\']*' . preg_quote($alt, '/') . '[^"\']*["\']/i', $content)) {
                $indicators[] = 'Generic alt: ' . $alt;
            }
        }

        return [
            'likely' => count($indicators) >= 2,
            'indicators' => array_unique(array_slice($indicators, 0, 5)),
        ];
    }

    private function calculateContentLevel(int $score): string
    {
        if ($score >= 90) {
            return 'excellent';
        }
        if ($score >= 70) {
            return 'good';
        }
        if ($score >= 50) {
            return 'fair';
        }

        return 'poor';
    }
}
