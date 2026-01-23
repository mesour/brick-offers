<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for displaying analyzer results.
 * Provides helper functions to format rawData values from analyzers.
 */
class AnalyzerDisplayExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('analyzer_get_value', $this->getValue(...)),
            new TwigFunction('analyzer_format_value', $this->formatValue(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Get a value from rawData using dot notation (e.g., 'metrics.lcp').
     */
    public function getValue(array $rawData, string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $rawData;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Format a value based on field configuration.
     *
     * @param array{
     *     type?: string,
     *     badgeMap?: array<string, string>,
     *     suffix?: string,
     *     maxLength?: int,
     *     decimals?: int
     * } $fieldConfig
     */
    public function formatValue(mixed $value, array $fieldConfig): string
    {
        if ($value === null) {
            return '<span class="text-muted">-</span>';
        }

        $type = $fieldConfig['type'] ?? 'text';

        return match ($type) {
            'boolean' => $this->formatBoolean($value),
            'badge' => $this->formatBadge($value, $fieldConfig['badgeMap'] ?? []),
            'number' => $this->formatNumber($value, $fieldConfig),
            'count' => is_array($value) ? (string) count($value) : (string) $value,
            'text' => $this->formatText($value, $fieldConfig),
            default => htmlspecialchars((string) $value),
        };
    }

    private function formatBoolean(mixed $value): string
    {
        $bool = (bool) $value;

        return $bool
            ? '<i class="fa fa-check text-success"></i> Ano'
            : '<i class="fa fa-times text-danger"></i> Ne';
    }

    /**
     * @param array<string, string> $badgeMap
     */
    private function formatBadge(mixed $value, array $badgeMap): string
    {
        $stringValue = (string) $value;
        $colorClass = $badgeMap[$stringValue] ?? 'secondary';

        return sprintf(
            '<span class="badge bg-%s">%s</span>',
            $colorClass,
            htmlspecialchars(ucfirst($stringValue)),
        );
    }

    /**
     * @param array{suffix?: string, decimals?: int} $config
     */
    private function formatNumber(mixed $value, array $config): string
    {
        $decimals = $config['decimals'] ?? 0;
        $suffix = $config['suffix'] ?? '';

        return number_format((float) $value, $decimals, ',', ' ') . htmlspecialchars($suffix);
    }

    /**
     * @param array{maxLength?: int} $config
     */
    private function formatText(mixed $value, array $config): string
    {
        $text = (string) $value;
        $maxLength = $config['maxLength'] ?? 0;

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '...';
        }

        return htmlspecialchars($text);
    }
}
