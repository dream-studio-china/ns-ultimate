<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Unit;

use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityFieldPolicy;
use PHPUnit\Framework\TestCase;

final class CapabilityFieldPolicyTest extends TestCase
{
    public function testNewlyAddedBusinessFieldIsDeniedUntilExplicitlyApproved(): void
    {
        $schema = [
            'name' => ['type' => 'string', 'maxLength' => 255, 'requiredOnCreate' => true, 'nonEmpty' => true],
            'slug' => ['type' => 'string', 'maxLength' => 255, 'requiredOnCreate' => true, 'nonEmpty' => true],
            'color' => ['type' => 'color', 'nullable' => true],
            'privateNote' => ['type' => 'string', 'maxLength' => 2000],
        ];

        $fields = CapabilityFieldPolicy::intersect($schema, ['name', 'slug', 'color']);

        self::assertSame(['name', 'slug', 'color'], array_keys($fields));
        self::assertArrayNotHasKey('privateNote', $fields);
        self::assertSame($schema['name'], $fields['name']);
        self::assertSame($schema['color'], $fields['color']);
    }

    public function testFieldRuleChangesInTheBusinessSchemaAreSerializedVerbatim(): void
    {
        $schema = [
            'name' => ['type' => 'string', 'maxLength' => 999, 'requiredOnCreate' => false, 'nonEmpty' => true],
        ];

        self::assertSame($schema, CapabilityFieldPolicy::intersect($schema, ['name']));
    }

    public function testPilotDescriptorKeepsExplicitActionsAndOmitsUnknownFields(): void
    {
        $descriptor = CapabilityFieldPolicy::descriptor(
            'Counter',
            static fn (string $action): array => $action === 'delete' ? [] : [
                'storeUuid' => ['type' => 'uuid'],
                'code' => ['type' => 'string', 'maxLength' => 50],
                'name' => ['type' => 'string', 'maxLength' => 120],
                'capacity' => ['type' => 'integer'],
                'sort' => ['type' => 'integer'],
                'enabled' => ['type' => 'boolean'],
                'metadata' => ['type' => 'object'],
            ],
        );

        self::assertNotNull($descriptor);
        self::assertSame(['create', 'update', 'delete'], $descriptor['actions']);
        self::assertSame(['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'], array_keys($descriptor['actionFields']['create']));
        self::assertSame(['code', 'name', 'capacity', 'sort', 'enabled'], array_keys($descriptor['actionFields']['update']));
        self::assertArrayNotHasKey('metadata', $descriptor['fields']);
    }

    public function testListShapedBusinessSchemaFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        CapabilityFieldPolicy::intersect([['name' => 'name']], ['name']);
    }
}
