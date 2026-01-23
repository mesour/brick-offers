<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class LibraryAnalyzer extends AbstractLeadAnalyzer
{
    /**
     * Library detection patterns with version extraction.
     *
     * @var array<string, array{patterns: array<string>, versionPattern: ?string, outdatedVersion: ?string, name: string}>
     */
    private const LIBRARIES = [
        'jquery' => [
            'patterns' => [
                '/jquery[.-]?(\d+\.\d+\.\d+)?(?:\.min)?\.js/i',
                '/jquery\.com\/jquery/i',
                '/code\.jquery\.com/i',
                '/jQuery\s*(?:JavaScript\s*Library)?\s*v?(\d+\.\d+\.\d+)/i',
            ],
            'versionPattern' => '/jquery[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => '3.5.0',
            'name' => 'jQuery',
        ],
        'bootstrap' => [
            'patterns' => [
                '/bootstrap[.-]?(\d+\.\d+\.\d+)?(?:\.min)?\.(?:js|css)/i',
                '/getbootstrap\.com/i',
                '/Bootstrap\s*v?(\d+\.\d+\.\d+)/i',
            ],
            'versionPattern' => '/bootstrap[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => '5.0.0',
            'name' => 'Bootstrap',
        ],
        'tailwind' => [
            'patterns' => [
                '/tailwindcss/i',
                '/tailwind[.-]?(\d+\.\d+\.\d+)?(?:\.min)?\.css/i',
                '/cdn\.tailwindcss\.com/i',
            ],
            'versionPattern' => '/tailwind[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => null,
            'name' => 'Tailwind CSS',
        ],
        'react' => [
            'patterns' => [
                '/react[.-]?(?:dom)?[.-]?(\d+\.\d+\.\d+)?(?:\.(?:development|production))?(?:\.min)?\.js/i',
                '/unpkg\.com\/react/i',
                '/cdnjs\..*\/react/i',
                '/__REACT_DEVTOOLS_GLOBAL_HOOK__/i',
                '/reactjs\.org/i',
            ],
            'versionPattern' => '/react[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => null,
            'name' => 'React',
        ],
        'vue' => [
            'patterns' => [
                '/vue[.-]?(\d+\.\d+\.\d+)?(?:\.(?:global|esm-browser))?(?:\.(?:prod|dev))?(?:\.min)?\.js/i',
                '/unpkg\.com\/vue/i',
                '/cdn\.jsdelivr\.net\/npm\/vue/i',
                '/__VUE__/i',
                '/vuejs\.org/i',
            ],
            'versionPattern' => '/vue[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => null,
            'name' => 'Vue.js',
        ],
        'angular' => [
            'patterns' => [
                '/angular[.-]?(?:core|common|router)?[.-]?(\d+\.\d+\.\d+)?(?:\.min)?\.js/i',
                '/ng-version/i',
                '/angular\.(?:io|dev)/i',
            ],
            'versionPattern' => '/angular[.-]?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => null,
            'name' => 'Angular',
        ],
        'wordpress' => [
            'patterns' => [
                '/wp-content\//i',
                '/wp-includes\//i',
                '/wordpress/i',
                '/wp-json\//i',
            ],
            'versionPattern' => '/WordPress\s*(\d+\.\d+(?:\.\d+)?)/i',
            'outdatedVersion' => null,
            'name' => 'WordPress',
        ],
        'woocommerce' => [
            'patterns' => [
                '/woocommerce/i',
                '/wc-blocks/i',
            ],
            'versionPattern' => '/woocommerce.*?(\d+\.\d+\.\d+)/i',
            'outdatedVersion' => null,
            'name' => 'WooCommerce',
        ],
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::LIBRARIES;
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getDescription(): string
    {
        return 'Detekuje použité JavaScript/CSS knihovny a frameworky, kontroluje zastaralé verze.';
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
            'detectedLibraries' => [],
            'outdatedLibraries' => [],
        ];

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null) {
            return AnalyzerResult::failure($this->getCategory(), 'Failed to fetch URL: ' . $result['error']);
        }

        $content = $result['content'] ?? '';

        // Detect libraries
        foreach (self::LIBRARIES as $libraryId => $library) {
            $detected = $this->detectLibrary($content, $library);

            if ($detected['found']) {
                $rawData['detectedLibraries'][$libraryId] = [
                    'name' => $library['name'],
                    'version' => $detected['version'],
                    'isOutdated' => $detected['isOutdated'],
                ];

                if ($detected['isOutdated'] && $detected['version'] !== null) {
                    $rawData['outdatedLibraries'][] = $libraryId;

                    $issueCode = 'lib_outdated_' . $libraryId;
                    $evidence = 'Detekovaná verze: ' . $detected['version'] . ', doporučená minimální verze: ' . $library['outdatedVersion'];

                    if (IssueRegistry::has($issueCode)) {
                        $issues[] = $this->createIssue($issueCode, $evidence);
                    } else {
                        $issues[] = $this->createCustomIssue(
                            \App\Enum\IssueSeverity::RECOMMENDED,
                            $issueCode,
                            'Zastaralá verze ' . $library['name'],
                            'Nalezena zastaralá verze knihovny ' . $library['name'] . '.',
                            $evidence,
                            'Zastaralé knihovny mohou obsahovat bezpečnostní zranitelnosti.',
                        );
                    }
                }
            }
        }

        // Check for mixed libraries (e.g., jQuery + React/Vue)
        $mixedLibrariesIssue = $this->checkMixedLibraries($rawData['detectedLibraries']);
        if ($mixedLibrariesIssue !== null) {
            $issues[] = $mixedLibrariesIssue;
            $rawData['mixedLibraries'] = true;
        }

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @param array{patterns: array<string>, versionPattern: ?string, outdatedVersion: ?string, name: string} $library
     * @return array{found: bool, version: ?string, isOutdated: bool}
     */
    private function detectLibrary(string $content, array $library): array
    {
        $found = false;
        $version = null;

        foreach ($library['patterns'] as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $found = true;

                // Try to extract version from pattern match
                if (isset($matches[1]) && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $matches[1])) {
                    $version = $matches[1];
                    break;
                }
            }
        }

        // Try dedicated version pattern if version not found
        if ($found && $version === null && $library['versionPattern'] !== null) {
            if (preg_match($library['versionPattern'], $content, $matches) && isset($matches[1])) {
                $version = $matches[1];
            }
        }

        $isOutdated = false;
        if ($found && $version !== null && $library['outdatedVersion'] !== null) {
            $isOutdated = version_compare($version, $library['outdatedVersion'], '<');
        }

        return [
            'found' => $found,
            'version' => $version,
            'isOutdated' => $isOutdated,
        ];
    }

    /**
     * Check for potentially problematic library combinations.
     *
     * @param array<string, array{name: string, version: ?string, isOutdated: bool}> $libraries
     */
    private function checkMixedLibraries(array $libraries): ?Issue
    {
        $hasJQuery = isset($libraries['jquery']);
        $hasReact = isset($libraries['react']);
        $hasVue = isset($libraries['vue']);
        $hasAngular = isset($libraries['angular']);

        $modernFrameworks = array_filter([$hasReact, $hasVue, $hasAngular]);

        // jQuery with modern framework
        if ($hasJQuery && count($modernFrameworks) > 0) {
            $frameworks = [];
            if ($hasReact) {
                $frameworks[] = 'React';
            }

            if ($hasVue) {
                $frameworks[] = 'Vue';
            }

            if ($hasAngular) {
                $frameworks[] = 'Angular';
            }

            return $this->createIssue('lib_jquery_with_modern_framework', 'Detekováno: jQuery + ' . implode(', ', $frameworks));
        }

        // Multiple modern frameworks
        if (count($modernFrameworks) > 1) {
            $frameworks = [];
            if ($hasReact) {
                $frameworks[] = 'React';
            }

            if ($hasVue) {
                $frameworks[] = 'Vue';
            }

            if ($hasAngular) {
                $frameworks[] = 'Angular';
            }

            return $this->createIssue('lib_multiple_frameworks', 'Detekováno: ' . implode(', ', $frameworks));
        }

        return null;
    }
}
