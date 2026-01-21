<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Modules\Stub;

use App\CmsModules\Settings\CmsModuleSettings;

/**
 * Stub implementation of CmsModuleSettings for unit testing.
 */
final class StubModuleSettings implements CmsModuleSettings
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private array $settings = [],
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->settings);
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
    }
}
