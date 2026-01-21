<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Frequency for crawling monitored domains.
 */
enum CrawlFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY = 'monthly';

    /**
     * Get the interval in days.
     */
    public function getDays(): int
    {
        return match ($this) {
            self::DAILY => 1,
            self::WEEKLY => 7,
            self::BIWEEKLY => 14,
            self::MONTHLY => 30,
        };
    }

    /**
     * Get display label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::BIWEEKLY => 'Bi-weekly',
            self::MONTHLY => 'Monthly',
        };
    }

    /**
     * Check if a domain should be crawled based on last crawl time.
     */
    public function shouldCrawl(?\DateTimeImmutable $lastCrawledAt): bool
    {
        if ($lastCrawledAt === null) {
            return true;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $this->getDays()));

        return $lastCrawledAt < $cutoff;
    }
}
