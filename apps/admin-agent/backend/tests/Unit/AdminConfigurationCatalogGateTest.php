<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Unit;

use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityCatalog;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use PHPUnit\Framework\TestCase;

final class AdminConfigurationCatalogGateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/admin-agent-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testManifestIntersectsActionsAndFieldsAndKeepsSafeDeletePreviewFields(): void
    {
        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'Content' => $this->entity('Content', ['create' => ['title', 'body'], 'update' => ['title']], true),
        ]));
        $catalog = $this->catalog($policy);
        $content = $catalog->build()['capabilities'][0];

        self::assertSame(['create', 'update'], $content['actions']);
        self::assertSame(['title', 'body'], array_keys($content['actionFields']['create']));
        self::assertSame(['title'], array_keys($content['actionFields']['update']));
        self::assertSame(['title', 'body'], array_keys($content['fields']));
        self::assertArrayNotHasKey('deletePreviewFields', $content);
        self::assertArrayNotHasKey('delete', $content['actionFields']);
    }

    public function testRequiredCreateFieldMissingFromManifestDisablesCreateInsteadOfDroppingIt(): void
    {
        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'Content' => $this->entity('Content', ['create' => ['body'], 'update' => ['title']], true),
        ]));
        $content = $this->catalog($policy)->build()['capabilities'][0];

        self::assertNotContains('create', $content['actions']);
        self::assertArrayNotHasKey('create', $content['actionFields']);
        self::assertContains('update', $content['actions']);
        self::assertNotContains('delete', $content['actions']);
    }

    public function testEntityAbsentOrDeleteDisabledIsOmittedOrLosesDelete(): void
    {
        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'NotACatalogEntity' => $this->entity('NotACatalogEntity', ['update' => ['name']], false),
        ]));
        self::assertSame([], $this->catalog($policy)->build()['capabilities']);

        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'Content' => $this->entity('Content', ['update' => ['title']], false),
        ]));
        $content = $this->catalog($policy)->build()['capabilities'][0];
        self::assertSame(['update'], $content['actions']);
        self::assertArrayNotHasKey('deletePreviewFields', $content);
    }

    public function testConfigurationPolicyDependencyIsMandatory(): void
    {
        $this->expectException(\ArgumentCountError::class);
        new CapabilityCatalog(new MutationAdapterRegistry([]), new BatchCreateAdapterRegistry([]));
    }

    public function testUnavailableManifestProducesAnEmptyCatalog(): void
    {
        $policy = new AdminConfigurationPolicy($this->directory.'/missing.json');
        $catalog = $this->catalog($policy)->build();

        self::assertSame([], $catalog['capabilities']);
        self::assertSame([], $catalog['batchCapabilities']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $catalog['revision']);
    }

    private function catalog(AdminConfigurationPolicy $policy): CapabilityCatalog
    {
        return new CapabilityCatalog(
            new MutationAdapterRegistry([$this->permissiveAdapter('Content'), $this->permissiveAdapter('Store')]),
            new BatchCreateAdapterRegistry([]),
            $policy,
        );
    }

    private function permissiveAdapter(string $entity): MutationAdapter
    {
        return new class($entity) implements MutationAdapter {
            public function __construct(private readonly string $entity) {}
            public function entityKey(): string { return $this->entity; }
            public function authorize(string $action, ?string $identifier): void {}
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
    }

    public function testSemanticAliasRequiresConfiguredSourcePathAndNarrowsNestedRules(): void
    {
        $store = $this->entity('Store', [
            'update' => [
                'name',
                ['property' => 'settings', 'nestedPaths' => [
                    'settings.printing.order.enabled',
                    'settings.printing.order.copies',
                ]],
            ],
        ], false);
        $semantic = [[
            'name' => 'StorePrinting',
            'entity' => 'Store',
            'actions' => ['update'],
            'fields' => [[
                'property' => 'order',
                'path' => 'settings.printing.order',
                'nestedPaths' => ['settings.printing.order.enabled', 'settings.printing.order.copies'],
            ]],
        ]];
        $file = $this->writeManifestWith(['Store' => $store], $semantic);
        $catalog = new CapabilityCatalog(
            new MutationAdapterRegistry([$this->permissiveAdapter('StorePrinting')]),
            new BatchCreateAdapterRegistry([]),
            new AdminConfigurationPolicy($file),
        );
        $printing = $catalog->build()['capabilities'][0];

        self::assertSame('StorePrinting', $printing['entity']);
        self::assertSame(['update'], $printing['actions']);
        self::assertSame(
            ['enabled', 'copies'],
            array_keys($printing['actionFields']['update']['order']['properties']),
        );
        self::assertArrayNotHasKey('deviceUuid', $printing['actionFields']['update']['order']['properties']);
        self::assertFalse($printing['actionFields']['update']['order']['nullable']);

        $storeWithoutLeaf = $this->entity('Store', [
            'update' => ['name', ['property' => 'settings', 'nestedPaths' => ['settings.printing.order.enabled']]],
        ], false);
        $file = $this->writeManifestWith(['Store' => $storeWithoutLeaf], $semantic);
        $catalog = new CapabilityCatalog(
            new MutationAdapterRegistry([$this->permissiveAdapter('StorePrinting')]),
            new BatchCreateAdapterRegistry([]),
            new AdminConfigurationPolicy($file),
        );
        self::assertSame([], $catalog->build()['capabilities']);
    }

    public function testNestedRulePropertiesAreNarrowedToConfiguredLeafPaths(): void
    {
        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'Content' => $this->entity('Content', [
                'create' => [
                    'title',
                    ['property' => 'metadata', 'nestedPaths' => ['metadata.featured']],
                ],
                'update' => ['title'],
            ], true),
        ]));
        $content = $this->catalog($policy)->build()['capabilities'][0];

        self::assertSame(['featured'], array_keys($content['actionFields']['create']['metadata']['properties']));
        self::assertArrayNotHasKey('layout', $content['actionFields']['create']['metadata']['properties']);
        self::assertFalse($content['actionFields']['create']['metadata']['nullable']);

        $policy = new AdminConfigurationPolicy($this->writeManifest([
            'Store' => $this->entity('Store', [
                'update' => [
                    'name',
                    ['property' => 'address', 'nestedPaths' => ['address.latitude']],
                ],
            ], false),
        ]));
        $store = $this->catalog($policy)->build()['capabilities'][0];
        self::assertSame(['name'], array_keys($store['actionFields']['update']), 'A partially paired address must not be advertised.');
    }

    /**
     * @param array<string, list<string|array{property: string, nestedPaths?: list<string>}>> $writeFields
     * @return array<string, mixed>
     */
    private function entity(string $name, array $writeFields, bool $delete): array
    {
        $actions = [];
        foreach (['create', 'update'] as $action) {
            $fields = [];
            foreach ($writeFields[$action] ?? [] as $field) {
                $fields[] = is_array($field) ? $field : ['property' => $field];
            }
            $actions[$action] = [
                'configured' => array_key_exists($action, $writeFields),
                'fields' => $fields,
            ];
        }
        $actions['delete'] = ['configured' => $delete, 'fields' => []];

        return ['key' => $name, 'name' => $name, 'actions' => $actions];
    }

    /** @param array<string, array<string, mixed>> $entities */
    private function writeManifest(array $entities): string
    {
        return $this->writeManifestWith($entities, []);
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param list<array<string, mixed>> $semantic
     */
    private function writeManifestWith(array $entities, array $semantic): string
    {
        $list = array_values($entities);
        usort($list, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $payload = [
            'schemaVersion' => AdminConfigurationPolicy::SCHEMA_VERSION,
            'source' => AdminConfigurationPolicy::SOURCE,
            'entities' => $list,
            'semanticCapabilities' => $semantic,
        ];
        $payload['revision'] = $this->revisionOf($payload);
        $file = $this->directory.'/manifest.json';
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));

        return $file;
    }

    /** @param array<string, mixed> $payload */
    private function revisionOf(array $payload): string
    {
        unset($payload['revision']);
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
