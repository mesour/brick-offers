<?php

declare(strict_types=1);

namespace App\Service\Storage;

interface StorageInterface
{
    /**
     * Upload a file to storage.
     *
     * @param string $path The path/key where the file will be stored
     * @param string $content The file content
     * @param string $mimeType The MIME type of the file
     */
    public function upload(string $path, string $content, string $mimeType = 'application/octet-stream'): void;

    /**
     * Download a file from storage.
     *
     * @param string $path The path/key of the file
     * @return string|null The file content or null if not found
     */
    public function download(string $path): ?string;

    /**
     * Delete a file from storage.
     *
     * @param string $path The path/key of the file
     */
    public function delete(string $path): void;

    /**
     * Check if a file exists in storage.
     *
     * @param string $path The path/key of the file
     */
    public function exists(string $path): bool;

    /**
     * Get a public URL for a file.
     *
     * @param string $path The path/key of the file
     * @param int $expiresIn Expiration time in seconds (for signed URLs)
     */
    public function getUrl(string $path, int $expiresIn = 3600): string;
}
