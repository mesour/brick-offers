<?php

declare(strict_types=1);

namespace App\Service\Competitor;

use App\Entity\CompetitorSnapshot;
use App\Entity\Lead;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Repository\CompetitorSnapshotRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for competitor monitoring implementations.
 */
abstract class AbstractCompetitorMonitor implements CompetitorMonitorInterface
{
    protected int $requestDelayMs = 1000;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly CompetitorSnapshotRepository $snapshotRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function supports(CompetitorSnapshotType $type): bool
    {
        return $type === $this->getType();
    }

    /**
     * Create a snapshot of the competitor's current state.
     */
    public function createSnapshot(Lead $competitor): ?CompetitorSnapshot
    {
        if (!$competitor->hasWebsite()) {
            return null;
        }

        try {
            // Fetch and extract data
            $rawData = $this->extractData($competitor);
            if (empty($rawData)) {
                return null;
            }

            // Calculate content hash
            $contentHash = CompetitorSnapshot::calculateHash($rawData);

            // Get previous snapshot for comparison
            $previousSnapshot = $this->snapshotRepository->findLatest($competitor, $this->getType());

            // Create new snapshot
            $snapshot = new CompetitorSnapshot();
            $snapshot->setLead($competitor);
            $snapshot->setSnapshotType($this->getType());
            $snapshot->setContentHash($contentHash);
            $snapshot->setRawData($rawData);
            $snapshot->setSourceUrl($this->getSourceUrl($competitor));
            $snapshot->setMetrics($this->calculateMetrics($rawData));

            if ($previousSnapshot !== null) {
                $snapshot->setPreviousSnapshot($previousSnapshot);

                // Check for changes
                if ($previousSnapshot->getContentHash() !== $contentHash) {
                    $changes = $this->detectChanges($previousSnapshot, $snapshot);
                    $snapshot->setChanges($changes);
                }
            }

            return $snapshot;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to create competitor snapshot', [
                'competitor' => $competitor->getDomain(),
                'type' => $this->getType()->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract data from the competitor's website.
     * Must be implemented by concrete monitors.
     *
     * @return array<string, mixed>
     */
    abstract protected function extractData(Lead $competitor): array;

    /**
     * Get the URL to analyze for this type.
     */
    protected function getSourceUrl(Lead $competitor): string
    {
        return $competitor->getUrl() ?? 'https://' . $competitor->getDomain();
    }

    /**
     * Calculate metrics from raw data.
     *
     * @return array<string, mixed>
     */
    protected function calculateMetrics(array $rawData): array
    {
        return [];
    }

    /**
     * Fetch HTML from URL with rate limiting.
     */
    protected function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs,en;q=0.5',
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->getContent();

        } catch (\Throwable $e) {
            $this->logger->debug('Failed to fetch URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Determine significance of a change.
     */
    protected function determineSignificance(string $field, mixed $before, mixed $after): ChangeSignificance
    {
        // Count-based changes
        if (is_numeric($before) && is_numeric($after)) {
            $percentChange = $before > 0 ? abs($after - $before) / $before * 100 : 100;

            if ($percentChange >= 50) {
                return ChangeSignificance::CRITICAL;
            }
            if ($percentChange >= 25) {
                return ChangeSignificance::HIGH;
            }
            if ($percentChange >= 10) {
                return ChangeSignificance::MEDIUM;
            }

            return ChangeSignificance::LOW;
        }

        // Array-based changes (items added/removed)
        if (is_array($before) && is_array($after)) {
            $added = array_diff($after, $before);
            $removed = array_diff($before, $after);

            $changeCount = count($added) + count($removed);
            $totalItems = max(count($before), count($after), 1);
            $percentChange = $changeCount / $totalItems * 100;

            if ($percentChange >= 50 || $changeCount >= 5) {
                return ChangeSignificance::HIGH;
            }
            if ($percentChange >= 20 || $changeCount >= 3) {
                return ChangeSignificance::MEDIUM;
            }

            return ChangeSignificance::LOW;
        }

        // String changes
        if (is_string($before) && is_string($after)) {
            $levenshtein = levenshtein($before, $after);
            $maxLen = max(strlen($before), strlen($after), 1);
            $percentChange = $levenshtein / $maxLen * 100;

            if ($percentChange >= 50) {
                return ChangeSignificance::HIGH;
            }
            if ($percentChange >= 20) {
                return ChangeSignificance::MEDIUM;
            }

            return ChangeSignificance::LOW;
        }

        // Default for other types
        return ChangeSignificance::MEDIUM;
    }

    /**
     * Apply rate limiting.
     */
    protected function rateLimit(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
