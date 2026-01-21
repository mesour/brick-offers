<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\Filesystem\Filesystem;

class LocalStorageService implements StorageInterface
{
    private readonly string $storagePath;
    private readonly Filesystem $filesystem;

    public function __construct(
        string $projectDir,
        private readonly string $publicUrlBase = '/storage',
    ) {
        $this->storagePath = $projectDir . '/var/storage';
        $this->filesystem = new Filesystem();

        // Ensure storage directory exists
        if (!$this->filesystem->exists($this->storagePath)) {
            $this->filesystem->mkdir($this->storagePath, 0755);
        }
    }

    public function upload(string $path, string $content, string $mimeType = 'application/octet-stream'): void
    {
        $fullPath = $this->getFullPath($path);
        $directory = dirname($fullPath);

        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory, 0755);
        }

        $this->filesystem->dumpFile($fullPath, $content);
    }

    public function download(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!$this->filesystem->exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);

        return $content !== false ? $content : null;
    }

    public function delete(string $path): void
    {
        $fullPath = $this->getFullPath($path);

        if ($this->filesystem->exists($fullPath)) {
            $this->filesystem->remove($fullPath);
        }
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($this->getFullPath($path));
    }

    public function getUrl(string $path, int $expiresIn = 3600): string
    {
        // For local storage, return a simple path
        // In production, this should be served by a web server or symlinked
        return $this->publicUrlBase . '/' . ltrim($path, '/');
    }

    private function getFullPath(string $path): string
    {
        // Sanitize path to prevent directory traversal
        $path = str_replace(['..', "\0"], '', $path);
        $path = ltrim($path, '/');

        return $this->storagePath . '/' . $path;
    }
}
