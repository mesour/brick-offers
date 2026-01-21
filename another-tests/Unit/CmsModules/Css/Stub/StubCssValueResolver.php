<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Css\Stub;

use App\CmsModules\Css\CssValueResolverInterface;
use App\Variables\Database\Variable;

/**
 * Stub CssValueResolver for unit testing
 * Implements CssValueResolverInterface without database dependencies
 */
final class StubCssValueResolver implements CssValueResolverInterface
{
    /**
     * @var array<int, array{value: string}|null>
     */
    private array $variables = [];

    /**
     * @var array<int, Variable|null>
     */
    private array $variableEntities = [];

    /**
     * Set a variable that will be returned by resolveValue/resolveJsonValue
     *
     * @param array{value: string}|null $variable
     */
    public function setVariable(int $id, array|null $variable): void
    {
        $this->variables[$id] = $variable;
    }

    /**
     * Set a Variable entity that will be returned by getVariable()
     */
    public function setVariableEntity(int $id, Variable|null $variable): void
    {
        $this->variableEntities[$id] = $variable;
    }

    /**
     * Resolves a module value that may contain a linked variable
     *
     * @param array{value?: string|mixed, linkedVariable?: array{id: int, type: string, value?: string}|null}|string|null $moduleValue
     */
    public function resolveValue(array|string|null $moduleValue, string $default = ''): string
    {
        if ($moduleValue === null) {
            return $default;
        }

        if (\is_string($moduleValue)) {
            return $moduleValue;
        }

        // Check for linked variable
        if (isset($moduleValue['linkedVariable']['id'])) {
            $variableId = $moduleValue['linkedVariable']['id'];

            if (isset($this->variables[$variableId])) {
                return $this->variables[$variableId]['value'];
            }

            // Fall back to stored variable value
            if (isset($moduleValue['linkedVariable']['value'])) {
                return $moduleValue['linkedVariable']['value'];
            }
        }

        // Return direct value
        return (string) ($moduleValue['value'] ?? $default);
    }

    /**
     * Resolves a value and returns parsed JSON array/object
     *
     * @param array{value?: string|mixed, linkedVariable?: array{id: int, type: string, value?: string}|null}|string|null $moduleValue
     * @param array<mixed> $default
     *
     * @return array<mixed>
     */
    public function resolveJsonValue(array|string|null $moduleValue, array $default = []): array
    {
        $value = $this->resolveValue($moduleValue, '');

        if ($value === '' || $value === '{}' || $value === '[]') {
            return $default;
        }

        try {
            $parsed = \json_decode($value, true, 512, \JSON_THROW_ON_ERROR);

            return \is_array($parsed) ? $parsed : $default;
        } catch (\JsonException) {
            return $default;
        }
    }

    /**
     * Get a variable by ID
     */
    public function getVariable(int $id): Variable|null
    {
        return $this->variableEntities[$id] ?? null;
    }
}
