<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Entity\AnalysisResult;
use App\Repository\AnalysisResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for archiving old analysis data according to retention policy.
 *
 * Retention Policy:
 * - 0-30 days: Full data (no action)
 * - 30-90 days: Compress rawData (gzip + base64)
 * - 90-365 days: Clear rawData, keep issues
 * - 365+ days: Delete AnalysisResult entirely
 */
class ArchiveService
{
    private const COMPRESSED_PREFIX = 'gz:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalysisResultRepository $resultRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run archive process with retention policy.
     */
    public function archive(
        int $compressAfterDays = 30,
        int $clearAfterDays = 90,
        int $deleteAfterDays = 365,
        int $batchSize = 100,
        bool $dryRun = false,
    ): ArchiveStats {
        $now = new \DateTimeImmutable();

        $this->logger->info('Starting archive process', [
            'compressAfterDays' => $compressAfterDays,
            'clearAfterDays' => $clearAfterDays,
            'deleteAfterDays' => $deleteAfterDays,
            'dryRun' => $dryRun,
        ]);

        // 1. Compress rawData for 30-90 days old results
        $compressFrom = $now->modify("-{$clearAfterDays} days");
        $compressTo = $now->modify("-{$compressAfterDays} days");
        $compressed = $this->compressOldRawData($compressFrom, $compressTo, $batchSize, $dryRun);

        // 2. Clear rawData for 90-365 days old results
        $clearFrom = $now->modify("-{$deleteAfterDays} days");
        $clearTo = $now->modify("-{$clearAfterDays} days");
        $cleared = $this->clearRawData($clearFrom, $clearTo, $batchSize, $dryRun);

        // 3. Delete results older than 365 days
        $deleteOlderThan = $now->modify("-{$deleteAfterDays} days");
        $deleted = $this->deleteOldResults($deleteOlderThan, $batchSize, $dryRun);

        $stats = new ArchiveStats($compressed, $cleared, $deleted);

        $this->logger->info('Archive process completed', [
            'compressed' => $compressed,
            'cleared' => $cleared,
            'deleted' => $deleted,
            'total' => $stats->getTotal(),
        ]);

        return $stats;
    }

    /**
     * Compress rawData for results in date range.
     * Uses gzip + base64 encoding with 'gz:' prefix.
     */
    private function compressOldRawData(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $batchSize,
        bool $dryRun,
    ): int {
        $count = 0;
        $processed = 0;

        do {
            $results = $this->resultRepository->findForCompression($from, $to, $batchSize);
            $batchCount = count($results);

            if ($batchCount === 0) {
                break;
            }

            foreach ($results as $result) {
                $rawData = $result->getRawData();

                // Skip if already compressed or empty
                if (empty($rawData) || $this->isCompressed($rawData)) {
                    continue;
                }

                if (!$dryRun) {
                    $compressed = $this->compressData($rawData);
                    $result->setRawData($compressed);
                }

                $count++;
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }

            $processed += $batchCount;

            $this->logger->debug('Compressed batch', ['count' => $batchCount, 'total' => $count]);
        } while ($batchCount === $batchSize);

        return $count;
    }

    /**
     * Clear rawData (set to empty array) for results in date range.
     */
    private function clearRawData(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $batchSize,
        bool $dryRun,
    ): int {
        if ($dryRun) {
            return $this->resultRepository->countForClearing($from, $to);
        }

        $totalCleared = 0;

        do {
            $cleared = $this->resultRepository->clearRawDataInRange($from, $to, $batchSize);
            $totalCleared += $cleared;

            $this->logger->debug('Cleared batch', ['count' => $cleared, 'total' => $totalCleared]);
        } while ($cleared === $batchSize);

        return $totalCleared;
    }

    /**
     * Delete AnalysisResult entities older than specified date.
     */
    private function deleteOldResults(
        \DateTimeImmutable $olderThan,
        int $batchSize,
        bool $dryRun,
    ): int {
        if ($dryRun) {
            return $this->resultRepository->countOlderThan($olderThan);
        }

        $totalDeleted = 0;

        do {
            $deleted = $this->resultRepository->deleteOlderThan($olderThan, $batchSize);
            $totalDeleted += $deleted;

            $this->logger->debug('Deleted batch', ['count' => $deleted, 'total' => $totalDeleted]);
        } while ($deleted === $batchSize);

        return $totalDeleted;
    }

    /**
     * Compress data using gzip + base64.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function compressData(array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 9);
        $encoded = base64_encode($compressed);

        return ['_compressed' => self::COMPRESSED_PREFIX . $encoded];
    }

    /**
     * Decompress data that was compressed with compressData().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decompressData(array $data): array
    {
        if (!$this->isCompressed($data)) {
            return $data;
        }

        $encoded = $data['_compressed'];
        $encoded = substr($encoded, strlen(self::COMPRESSED_PREFIX));
        $compressed = base64_decode($encoded);
        $json = gzdecode($compressed);

        return json_decode($json, true) ?? [];
    }

    /**
     * Check if data is compressed.
     *
     * @param array<string, mixed> $data
     */
    private function isCompressed(array $data): bool
    {
        return isset($data['_compressed'])
            && is_string($data['_compressed'])
            && str_starts_with($data['_compressed'], self::COMPRESSED_PREFIX);
    }

    /**
     * Get counts for dry run preview.
     *
     * @return array{compress: int, clear: int, delete: int}
     */
    public function getArchiveCounts(
        int $compressAfterDays = 30,
        int $clearAfterDays = 90,
        int $deleteAfterDays = 365,
    ): array {
        $now = new \DateTimeImmutable();

        $compressFrom = $now->modify("-{$clearAfterDays} days");
        $compressTo = $now->modify("-{$compressAfterDays} days");

        $clearFrom = $now->modify("-{$deleteAfterDays} days");
        $clearTo = $now->modify("-{$clearAfterDays} days");

        $deleteOlderThan = $now->modify("-{$deleteAfterDays} days");

        return [
            'compress' => $this->resultRepository->countForCompression($compressFrom, $compressTo),
            'clear' => $this->resultRepository->countForClearing($clearFrom, $clearTo),
            'delete' => $this->resultRepository->countOlderThan($deleteOlderThan),
        ];
    }
}
