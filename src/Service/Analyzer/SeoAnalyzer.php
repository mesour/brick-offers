<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class SeoAnalyzer extends AbstractLeadAnalyzer
{
    private const TITLE_MIN_LENGTH = 10;
    private const TITLE_MAX_LENGTH = 70;
    private const DESCRIPTION_MIN_LENGTH = 50;
    private const DESCRIPTION_MAX_LENGTH = 160;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::SEO;
    }

    public function getPriority(): int
    {
        return 30;
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

        $content = $result['content'] ?? '';

        // Check title tag
        $titleResult = $this->checkTitleTag($content);
        $rawData['checks']['title'] = $titleResult['data'];
        foreach ($titleResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check meta description
        $descResult = $this->checkMetaDescription($content);
        $rawData['checks']['metaDescription'] = $descResult['data'];
        foreach ($descResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check Open Graph tags
        $ogResult = $this->checkOpenGraphTags($content);
        $rawData['checks']['openGraph'] = $ogResult['data'];
        foreach ($ogResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check viewport meta tag
        $viewportResult = $this->checkViewportTag($content);
        $rawData['checks']['viewport'] = $viewportResult['data'];
        foreach ($viewportResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check H1 structure
        $h1Result = $this->checkH1Structure($content);
        $rawData['checks']['h1'] = $h1Result['data'];
        foreach ($h1Result['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check for sitemap.xml
        $sitemapResult = $this->checkSitemap($url);
        $rawData['checks']['sitemap'] = $sitemapResult['data'];
        foreach ($sitemapResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check for robots.txt
        $robotsResult = $this->checkRobotsTxt($url);
        $rawData['checks']['robots'] = $robotsResult['data'];
        foreach ($robotsResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check images for alt attributes
        $imagesResult = $this->checkImageAltAttributes($content);
        $rawData['checks']['images'] = $imagesResult['data'];
        foreach ($imagesResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTitleTag(string $content): array
    {
        $issues = [];
        $title = null;

        if (preg_match('/<title[^>]*>([^<]*)<\/title>/is', $content, $matches)) {
            $title = trim($matches[1]);
        }

        $data = [
            'title' => $title,
            'length' => $title !== null ? mb_strlen($title) : 0,
        ];

        if ($title === null || empty($title)) {
            $issues[] = $this->createIssue('seo_missing_title', 'Title tag nebyl nalezen v HTML');
        } elseif (mb_strlen($title) < self::TITLE_MIN_LENGTH) {
            $issues[] = $this->createIssue('seo_short_title', "Title má pouze " . mb_strlen($title) . " znaků: \"{$title}\"");
        } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $issues[] = $this->createIssue('seo_long_title', "Title má " . mb_strlen($title) . " znaků");
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkMetaDescription(string $content): array
    {
        $issues = [];
        $description = null;

        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $content, $matches)) {
            $description = trim($matches[1]);
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/is', $content, $matches)) {
            $description = trim($matches[1]);
        }

        $data = [
            'description' => $description,
            'length' => $description !== null ? mb_strlen($description) : 0,
        ];

        if ($description === null || empty($description)) {
            $issues[] = $this->createIssue('seo_missing_description', 'Meta description nebyl nalezen v HTML');
        } elseif (mb_strlen($description) < self::DESCRIPTION_MIN_LENGTH) {
            $issues[] = $this->createIssue('seo_short_description', "Description má pouze " . mb_strlen($description) . " znaků");
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $issues[] = $this->createIssue('seo_long_description', "Description má " . mb_strlen($description) . " znaků");
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkOpenGraphTags(string $content): array
    {
        $issues = [];
        $ogTags = [
            'og:title' => null,
            'og:description' => null,
            'og:image' => null,
            'og:url' => null,
        ];

        foreach (array_keys($ogTags) as $tag) {
            if (preg_match('/<meta[^>]+property=["\']' . preg_quote($tag, '/') . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $content, $matches)) {
                $ogTags[$tag] = trim($matches[1]);
            } elseif (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']' . preg_quote($tag, '/') . '["\'][^>]*>/is', $content, $matches)) {
                $ogTags[$tag] = trim($matches[1]);
            }
        }

        $missingTags = array_keys(array_filter($ogTags, fn ($v) => $v === null || $v === ''));

        $data = [
            'tags' => $ogTags,
            'missingTags' => $missingTags,
        ];

        if (count($missingTags) > 0) {
            $issues[] = $this->createIssue('seo_missing_og_tags', 'Chybí: ' . implode(', ', $missingTags));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkViewportTag(string $content): array
    {
        $issues = [];
        $viewport = null;

        if (preg_match('/<meta[^>]+name=["\']viewport["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $content, $matches)) {
            $viewport = trim($matches[1]);
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']viewport["\'][^>]*>/is', $content, $matches)) {
            $viewport = trim($matches[1]);
        }

        $data = [
            'viewport' => $viewport,
        ];

        if ($viewport === null || empty($viewport)) {
            $issues[] = $this->createIssue('seo_missing_viewport', 'Viewport meta tag nebyl nalezen v HTML');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkH1Structure(string $content): array
    {
        $issues = [];
        $h1Tags = [];

        if (preg_match_all('/<h1[^>]*>([^<]*)<\/h1>/is', $content, $matches)) {
            $h1Tags = array_map('trim', $matches[1]);
        }

        $data = [
            'count' => count($h1Tags),
            'h1Tags' => $h1Tags,
        ];

        if (count($h1Tags) === 0) {
            $issues[] = $this->createIssue('seo_missing_h1', 'H1 tag nebyl nalezen v HTML');
        } elseif (count($h1Tags) > 1) {
            $issues[] = $this->createIssue('seo_multiple_h1', 'Nalezeno ' . count($h1Tags) . ' H1 tagů');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkSitemap(string $url): array
    {
        $issues = [];
        $baseUrl = $this->getBaseUrlFromString($url);
        $sitemapUrl = $baseUrl . '/sitemap.xml';

        $result = $this->fetchUrl($sitemapUrl);

        $data = [
            'url' => $sitemapUrl,
            'exists' => $result['statusCode'] === 200,
            'statusCode' => $result['statusCode'],
        ];

        if ($result['statusCode'] !== 200) {
            $issues[] = $this->createIssue('seo_missing_sitemap', "GET {$sitemapUrl} vrátil status " . ($result['statusCode'] ?? 'N/A'));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkRobotsTxt(string $url): array
    {
        $issues = [];
        $baseUrl = $this->getBaseUrlFromString($url);
        $robotsUrl = $baseUrl . '/robots.txt';

        $result = $this->fetchUrl($robotsUrl);

        $data = [
            'url' => $robotsUrl,
            'exists' => $result['statusCode'] === 200,
            'statusCode' => $result['statusCode'],
        ];

        if ($result['statusCode'] !== 200) {
            $issues[] = $this->createIssue('seo_missing_robots', "GET {$robotsUrl} vrátil status " . ($result['statusCode'] ?? 'N/A'));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkImageAltAttributes(string $content): array
    {
        $issues = [];
        $totalImages = 0;
        $imagesWithoutAlt = 0;

        if (preg_match_all('/<img[^>]*>/is', $content, $matches)) {
            $totalImages = count($matches[0]);

            foreach ($matches[0] as $imgTag) {
                // Check if alt attribute is missing or empty
                if (!preg_match('/\salt=["\'][^"\']+["\']/', $imgTag)) {
                    $imagesWithoutAlt++;
                }
            }
        }

        $data = [
            'totalImages' => $totalImages,
            'imagesWithoutAlt' => $imagesWithoutAlt,
            'percentage' => $totalImages > 0 ? round(($imagesWithoutAlt / $totalImages) * 100, 1) : 0,
        ];

        if ($imagesWithoutAlt > 0 && $totalImages > 0) {
            $percentage = round(($imagesWithoutAlt / $totalImages) * 100);
            $issues[] = $this->createIssue('seo_images_missing_alt', "{$imagesWithoutAlt} z {$totalImages} obrázků ({$percentage}%) nemá alt");
        }

        return ['data' => $data, 'issues' => $issues];
    }

    private function getBaseUrlFromString(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
