<?php

declare(strict_types=1);

namespace App\Service\Archive;

/**
 * Statistics from archive operation.
 */
readonly class ArchiveStats
{
    public function __construct(
        public int $compressed = 0,
        public int $cleared = 0,
        public int $deleted = 0,
        public int $skipped = 0,
    ) {
    }

    public function getTotal(): int
    {
        return $this->compressed + $this->cleared + $this->deleted;
    }

    public function withCompressed(int $count): self
    {
        return new self($count, $this->cleared, $this->deleted, $this->skipped);
    }

    public function withCleared(int $count): self
    {
        return new self($this->compressed, $count, $this->deleted, $this->skipped);
    }

    public function withDeleted(int $count): self
    {
        return new self($this->compressed, $this->cleared, $count, $this->skipped);
    }

    public function withSkipped(int $count): self
    {
        return new self($this->compressed, $this->cleared, $this->deleted, $count);
    }
}
