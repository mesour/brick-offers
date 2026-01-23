<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Service\Analyzer\IssueRegistry;
use App\Service\Analyzer\LeadAnalyzerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Service for managing analyzer configuration schemas.
 * Provides analyzer metadata for UI configuration.
 */
class AnalyzerConfigService
{
    /** @var array<LeadAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param iterable<LeadAnalyzerInterface> $analyzers
     */
    public function __construct(
        #[TaggedIterator('app.lead_analyzer')]
        iterable $analyzers
    ) {
        $this->analyzers = iterator_to_array($analyzers);
    }

    /**
     * Get all analyzers available for a given industry.
     *
     * @return array<LeadAnalyzerInterface>
     */
    public function getAnalyzersForIndustry(?Industry $industry): array
    {
        return array_filter(
            $this->analyzers,
            fn (LeadAnalyzerInterface $analyzer) => $analyzer->supportsIndustry($industry)
        );
    }

    /**
     * Get analyzer configuration schemas for UI.
     *
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     category: IssueCategory,
     *     categoryCode: string,
     *     universal: bool,
     *     priority: int,
     *     settings: array<string, array{type: string, label: string, default: mixed, min?: int|float, max?: int|float, step?: int|float}>,
     *     availableIssueCodes: array<string, array{title: string, severity: string}>
     * }>
     */
    public function getAnalyzerSchemas(?Industry $industry): array
    {
        $schemas = [];
        $analyzers = $this->getAnalyzersForIndustry($industry);

        foreach ($analyzers as $analyzer) {
            $category = $analyzer->getCategory();
            $categoryCode = $category->value;

            // Get available issue codes for this category
            $issueCodes = [];
            foreach (IssueRegistry::getByCategory($category) as $code => $def) {
                $issueCodes[$code] = [
                    'title' => $def['title'],
                    'severity' => $def['severity']->value,
                ];
            }

            $schemas[$categoryCode] = [
                'name' => $analyzer->getName(),
                'description' => $analyzer->getDescription(),
                'category' => $category,
                'categoryCode' => $categoryCode,
                'universal' => $analyzer->isUniversal(),
                'priority' => $analyzer->getPriority(),
                'settings' => $analyzer->getConfigurableSettings(),
                'availableIssueCodes' => $issueCodes,
            ];
        }

        // Sort by priority (universal first, then by priority)
        uasort($schemas, function ($a, $b) {
            if ($a['universal'] !== $b['universal']) {
                return $b['universal'] <=> $a['universal'];
            }

            return $a['priority'] <=> $b['priority'];
        });

        return $schemas;
    }

    /**
     * Merge user configuration with analyzer defaults.
     *
     * @param array<string, array{enabled?: bool, priority?: int, thresholds?: array<string, mixed>, ignoreCodes?: array<string>}> $config
     * @return array<string, array{
     *     enabled: bool,
     *     priority: int,
     *     thresholds: array<string, mixed>,
     *     ignoreCodes: array<string>,
     *     name: string,
     *     description: string,
     *     categoryCode: string,
     *     universal: bool,
     *     settings: array<string, array{type: string, label: string, default: mixed, min?: int|float, max?: int|float, step?: int|float}>,
     *     availableIssueCodes: array<string, array{title: string, severity: string}>
     * }>
     */
    public function mergeWithDefaults(array $config, ?Industry $industry): array
    {
        $schemas = $this->getAnalyzerSchemas($industry);
        $merged = [];

        foreach ($schemas as $categoryCode => $schema) {
            $userConfig = $config[$categoryCode] ?? [];

            // Build default thresholds from settings
            $defaultThresholds = [];
            foreach ($schema['settings'] as $key => $setting) {
                $defaultThresholds[$key] = $setting['default'];
            }

            $merged[$categoryCode] = [
                'enabled' => $userConfig['enabled'] ?? true,
                'priority' => $userConfig['priority'] ?? 5,
                'thresholds' => array_merge($defaultThresholds, $userConfig['thresholds'] ?? []),
                'ignoreCodes' => $userConfig['ignoreCodes'] ?? [],
                'name' => $schema['name'],
                'description' => $schema['description'],
                'categoryCode' => $categoryCode,
                'universal' => $schema['universal'],
                'settings' => $schema['settings'],
                'availableIssueCodes' => $schema['availableIssueCodes'],
            ];
        }

        return $merged;
    }
}
