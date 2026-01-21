<?php

declare(strict_types=1);

namespace App\Service\Extractor;

interface ContactExtractorInterface
{
    /**
     * Extract data from HTML content.
     *
     * @return array<mixed>
     */
    public function extract(string $html): array;
}
