<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class VisualAnalyzer extends AbstractBrowserAnalyzer
{
    // Thresholds for visual consistency
    private const MAX_PADDING_VALUES = 10;
    private const MAX_FONT_SIZES = 8;
    private const MAX_FONT_FAMILIES = 4;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::VISUAL;
    }

    public function getPriority(): int
    {
        return 70; // After responsiveness
    }

    public function getDescription(): string
    {
        return 'Kontroluje vizuální konzistenci: padding, fonty, velikosti písma a kvalitu obrázků.';
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        // Check browser availability
        $browserCheck = $this->ensureBrowserAvailable();
        if ($browserCheck !== null) {
            return $browserCheck;
        }

        $issues = [];
        $rawData = [
            'url' => $url,
            'analyzedAt' => date('c'),
        ];

        try {
            $visualData = $this->browser->analyzeVisualConsistency($url);
            $rawData['paddingValues'] = $visualData['paddingValues'];
            $rawData['fontFamilies'] = $visualData['fontFamilies'];
            $rawData['fontSizes'] = $visualData['fontSizes'];
            $rawData['upscaledImages'] = $visualData['upscaledImages'];

            // Analyze padding consistency
            $paddingIssues = $this->analyzePaddingConsistency($visualData['paddingValues']);
            foreach ($paddingIssues as $issue) {
                $issues[] = $issue;
            }

            // Analyze typography consistency
            $typographyIssues = $this->analyzeTypographyConsistency(
                $visualData['fontFamilies'],
                $visualData['fontSizes']
            );
            foreach ($typographyIssues as $issue) {
                $issues[] = $issue;
            }

            // Analyze image quality
            $imageIssues = $this->analyzeImageQuality($visualData['upscaledImages']);
            foreach ($imageIssues as $issue) {
                $issues[] = $issue;
            }

            return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
        } catch (\Throwable $e) {
            $this->logger->error('Visual analysis failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Visual analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, int> $paddingValues
     * @return array<Issue>
     */
    private function analyzePaddingConsistency(array $paddingValues): array
    {
        $issues = [];
        $uniqueValues = count($paddingValues);

        if ($uniqueValues > self::MAX_PADDING_VALUES) {
            // Get top 5 most used values
            arsort($paddingValues);
            $topValues = array_slice(array_keys($paddingValues), 0, 5);
            $valuesExample = implode(', ', $topValues);

            $issues[] = $this->createIssue('visual_inconsistent_padding', sprintf('Nejčastější hodnoty: %s', $valuesExample));
        }

        return $issues;
    }

    /**
     * @param array<string, int> $fontFamilies
     * @param array<string, int> $fontSizes
     * @return array<Issue>
     */
    private function analyzeTypographyConsistency(array $fontFamilies, array $fontSizes): array
    {
        $issues = [];

        // Check font families
        $uniqueFonts = count($fontFamilies);
        if ($uniqueFonts > self::MAX_FONT_FAMILIES) {
            arsort($fontFamilies);
            $topFonts = array_slice(array_keys($fontFamilies), 0, 4);
            $fontsExample = implode(', ', $topFonts);

            $issues[] = $this->createIssue('visual_many_fonts', sprintf('Použité fonty: %s', $fontsExample));
        }

        // Check font sizes
        $uniqueSizes = count($fontSizes);
        if ($uniqueSizes > self::MAX_FONT_SIZES) {
            arsort($fontSizes);
            $topSizes = array_slice(array_keys($fontSizes), 0, 5);
            $sizesExample = implode(', ', $topSizes);

            $issues[] = $this->createIssue('visual_inconsistent_typography', sprintf('Nejčastější velikosti: %s', $sizesExample));
        }

        return $issues;
    }

    /**
     * @param array<array{src: string, naturalWidth: int, displayWidth: int, ratio: float}> $upscaledImages
     * @return array<Issue>
     */
    private function analyzeImageQuality(array $upscaledImages): array
    {
        $issues = [];

        if (count($upscaledImages) > 0) {
            // Build examples
            $examples = array_slice($upscaledImages, 0, 3);
            $examplesText = implode("\n", array_map(
                fn ($img) => sprintf(
                    '- %s (přirozená: %dpx, zobrazená: %dpx)',
                    substr($img['src'], -50),
                    $img['naturalWidth'],
                    $img['displayWidth']
                ),
                $examples
            ));

            $issues[] = $this->createIssue('visual_upscaled_images', $examplesText);
        }

        return $issues;
    }
}
