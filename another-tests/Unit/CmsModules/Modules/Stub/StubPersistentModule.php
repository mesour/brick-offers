<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Modules\Stub;

use App\CmsModules\Database\PersistentModuleEntity;

/**
 * Stub implementation of PersistentModuleEntity for unit testing.
 */
final class StubPersistentModule implements PersistentModuleEntity
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly int $id = 1,
        private readonly string $type = 'link',
        private readonly array $settings = [],
        private readonly int $sort = 0,
        private readonly int|null $parentId = null,
        private readonly int|null $originalModuleId = null,
        private readonly string $status = 'unchanged',
    )
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function getParentId(): int|null
    {
        return $this->parentId;
    }

    public function getOriginalModuleId(): int|null
    {
        return $this->originalModuleId;
    }

    public function getStatusString(): string
    {
        return $this->status;
    }
}
