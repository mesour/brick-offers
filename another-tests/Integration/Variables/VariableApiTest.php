<?php declare(strict_types = 1);

namespace Tests\Integration\Variables;

use App\Variables\Database\Variable;
use App\Variables\Database\VariableGroup;
use App\Variables\Database\VariableGroupRepository;
use App\Variables\Database\VariableRepository;
use App\Variables\VariableType;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Variable API endpoints.
 *
 * @group integration
 * @group database
 */
final class VariableApiTest extends ApiIntegrationTestCase
{
    private VariableGroupRepository $variableGroupRepository;
    private VariableRepository $variableRepository;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            return;
        }

        $this->variableGroupRepository = $this->getService(VariableGroupRepository::class);
        $this->variableRepository = $this->getService(VariableRepository::class);
    }

    // =========================================================================
    // GET /api/variables
    // =========================================================================

    #[Test]
    public function getVariablesReturnsEmptyArrayWhenNoGroups(): void
    {
        $result = $this->apiGet('variables');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['groups']);
        self::assertEmpty($data['groups']);
    }

    #[Test]
    public function getVariablesReturnsAllGroupsWithVariables(): void
    {
        // Create groups with variables
        $group1 = new VariableGroup('Colors');
        $this->variableGroupRepository->save($group1);

        $var1 = new Variable($group1, 'Primary', VariableType::Color, '#FF0000');
        $this->variableRepository->save($var1);

        $group2 = new VariableGroup('Spacing');
        $this->variableGroupRepository->save($group2);

        $result = $this->apiGet('variables');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['groups']);
        self::assertCount(2, $data['groups']);
    }

    // =========================================================================
    // GET /api/variable
    // =========================================================================

    #[Test]
    public function getVariableReturnsVariableData(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'Test Variable', VariableType::Color, '#00FF00');
        $this->variableRepository->save($variable);

        $result = $this->apiGet('variable', ['id' => (string) $variable->getId()]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('Test Variable', $data['name']);
        self::assertEquals('#00FF00', $data['value']);
        self::assertEquals('color', $data['type']);
    }

    #[Test]
    public function getVariableReturns404ForNonExistent(): void
    {
        $result = $this->apiGet('variable', ['id' => '999999']);

        $this->assertApiStatusCode(404, $result);
        $this->assertApiError('VARIABLE_NOT_FOUND', $result);
    }

    // =========================================================================
    // POST /api/variable-group-create
    // =========================================================================

    #[Test]
    public function createVariableGroup(): void
    {
        $result = $this->apiPost('variableGroupCreate', ['name' => 'New Group']);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertEquals('New Group', $data['name']);
    }

    // =========================================================================
    // POST /api/variable-group-update
    // =========================================================================

    #[Test]
    public function updateVariableGroup(): void
    {
        $group = new VariableGroup('Old Name');
        $this->variableGroupRepository->save($group);

        $result = $this->apiPost('variableGroupUpdate', [
            'id' => (string) $group->getId(),
            'name' => 'New Name',
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('New Name', $data['name']);
    }

    #[Test]
    public function updateVariableGroupReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('variableGroupUpdate', [
            'id' => '999999',
            'name' => 'New Name',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/variable-group-delete
    // =========================================================================

    #[Test]
    public function deleteVariableGroup(): void
    {
        $group = new VariableGroup('Group To Delete');
        $this->variableGroupRepository->save($group);

        $result = $this->apiPost('variableGroupDelete', ['id' => (string) $group->getId()]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function deleteVariableGroupReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('variableGroupDelete', ['id' => '999999']);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/variable-create
    // =========================================================================

    #[Test]
    public function createVariable(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $result = $this->apiPost('variableCreate', [
            'groupId' => (string) $group->getId(),
            'name' => 'New Variable',
            'type' => 'color',
            'value' => '#0000FF',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertEquals('New Variable', $data['name']);
        self::assertEquals('#0000FF', $data['value']);
        self::assertEquals('color', $data['type']);
    }

    #[Test]
    public function createVariableWithSpacingProperty(): void
    {
        $group = new VariableGroup('Spacing Group');
        $this->variableGroupRepository->save($group);

        $result = $this->apiPost('variableCreate', [
            'groupId' => (string) $group->getId(),
            'name' => 'Padding Variable',
            'type' => 'spacing',
            'value' => '{"value":16,"unit":"px"}',
            'spacingProperty' => 'padding',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('spacing', $data['type']);
        self::assertEquals('padding', $data['spacingProperty']);
    }

    #[Test]
    public function createVariableRejectsInvalidType(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $result = $this->apiPost('variableCreate', [
            'groupId' => (string) $group->getId(),
            'name' => 'Invalid Variable',
            'type' => 'invalid_type',
            'value' => 'some value',
        ]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_TYPE', $result);
    }

    #[Test]
    public function createVariableReturns404ForNonExistentGroup(): void
    {
        $result = $this->apiPost('variableCreate', [
            'groupId' => '999999',
            'name' => 'Variable',
            'type' => 'color',
            'value' => '#FF0000',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/variable-update
    // =========================================================================

    #[Test]
    public function updateVariable(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'My Variable', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $initialVersion = $variable->getVersion();

        $result = $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#00FF00',
            'version' => (string) $initialVersion,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('newVersion', $data);
    }

    #[Test]
    public function updateVariableVersionConflict(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'My Variable', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $initialVersion = $variable->getVersion();

        // First update
        $result1 = $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#00FF00',
            'version' => (string) $initialVersion,
        ]);
        $this->assertApiSuccess($result1);

        // Second update with old version
        $result2 = $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#0000FF',
            'version' => (string) $initialVersion,
        ]);

        $this->assertApiStatusCode(409, $result2);
        $this->assertApiError('VERSION_CONFLICT', $result2);
    }

    #[Test]
    public function updateVariableForceOverwrite(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'My Variable', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $initialVersion = $variable->getVersion();

        // First update
        $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#00FF00',
            'version' => (string) $initialVersion,
        ]);

        // Force update with old version
        $result = $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#0000FF',
            'version' => (string) $initialVersion,
            'force' => '1',
        ]);

        $this->assertApiSuccess($result);
    }

    // =========================================================================
    // POST /api/variable-rename
    // =========================================================================

    #[Test]
    public function renameVariable(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'Old Name', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $result = $this->apiPost('variableRename', [
            'id' => (string) $variable->getId(),
            'name' => 'New Name',
            'version' => (string) $variable->getVersion(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function renameVariableVersionConflict(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'Original Name', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $initialVersion = $variable->getVersion();

        // First rename
        $result1 = $this->apiPost('variableRename', [
            'id' => (string) $variable->getId(),
            'name' => 'First Rename',
            'version' => (string) $initialVersion,
        ]);
        $this->assertApiSuccess($result1);

        // Second rename with old version
        $result2 = $this->apiPost('variableRename', [
            'id' => (string) $variable->getId(),
            'name' => 'Second Rename',
            'version' => (string) $initialVersion,
        ]);

        $this->assertApiStatusCode(409, $result2);
        $this->assertApiError('VERSION_CONFLICT', $result2);
    }

    // =========================================================================
    // POST /api/variable-delete
    // =========================================================================

    #[Test]
    public function deleteVariable(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'Variable To Delete', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $result = $this->apiPost('variableDelete', [
            'id' => (string) $variable->getId(),
            'version' => (string) $variable->getVersion(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function deleteVariableVersionConflict(): void
    {
        $group = new VariableGroup('Test Group');
        $this->variableGroupRepository->save($group);

        $variable = new Variable($group, 'Variable', VariableType::Color, '#FF0000');
        $this->variableRepository->save($variable);

        $initialVersion = $variable->getVersion();

        // Update variable first to change version
        $this->apiPost('variableUpdate', [
            'id' => (string) $variable->getId(),
            'value' => '#00FF00',
            'version' => (string) $initialVersion,
        ]);

        // Delete with old version
        $result = $this->apiPost('variableDelete', [
            'id' => (string) $variable->getId(),
            'version' => (string) $initialVersion,
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('VERSION_CONFLICT', $result);
    }

    #[Test]
    public function deleteVariableReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('variableDelete', [
            'id' => '999999',
            'version' => '1',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // Different variable types
    // =========================================================================

    #[Test]
    public function createVariablesWithDifferentTypes(): void
    {
        $group = new VariableGroup('All Types');
        $this->variableGroupRepository->save($group);

        $types = [
            ['type' => 'number', 'value' => '42'],
            ['type' => 'color', 'value' => '#FF0000'],
            ['type' => 'string', 'value' => 'Hello World'],
            ['type' => 'boolean', 'value' => 'true'],
            ['type' => 'font', 'value' => '{"family":"Arial","weight":"400"}'],
        ];

        foreach ($types as $typeData) {
            $result = $this->apiPost('variableCreate', [
                'groupId' => (string) $group->getId(),
                'name' => 'Var ' . $typeData['type'],
                'type' => $typeData['type'],
                'value' => $typeData['value'],
            ]);

            $this->assertApiStatusCode(201, $result, 'Failed to create variable of type: ' . $typeData['type']);
        }
    }
}
