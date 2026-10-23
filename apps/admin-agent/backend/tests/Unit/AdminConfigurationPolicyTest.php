<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Unit;

use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AdminConfigurationPolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/admin-agent-policy-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testMissingMalformedAndTamperedArtifactsFailClosed(): void
    {
        $missing = new AdminConfigurationPolicy($this->directory.'/missing.json');
        self::assertFalse($missing->isAvailable());
        self::assertFalse($missing->allows('Tag', 'update', ['name' => 'A']));

        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], ['delete' => true]),
        ]));
        self::assertTrue((new AdminConfigurationPolicy($file))->isAvailable());

        file_put_contents($file, '{not-json');
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $valid = $this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], ['delete' => true]),
        ]);
        $valid['entities'][0]['actions']['update']['fields'][] = ['property' => 'slug'];
        file_put_contents($file, json_encode($valid, JSON_THROW_ON_ERROR));
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable(), 'Revision tampering must fail closed.');
    }

    public function testPrototypeUnsafeAndMalformedNamesFailClosed(): void
    {
        $unsafe = $this->manifest([
            '__proto__' => $this->entity('__proto__', ['update' => ['name']], []),
        ]);
        $file = $this->writeManifest($unsafe);
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $unsafeField = $this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['__proto__']], []),
        ]);
        $file = $this->writeManifest($unsafeField);
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $wrongSource = $this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], []),
        ]);
        $wrongSource['source'] = 'client/config.json';
        $file = $this->writeManifest($wrongSource);
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());
    }

    public function testPrepareAndMutationGatesRejectUnknownEntitiesActionsAndFields(): void
    {
        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['create' => ['name', 'slug'], 'update' => ['name']], ['delete' => true]),
        ]));
        $policy = new AdminConfigurationPolicy($file);

        $policy->assertPrepareAllowed('Tag', 'update', ['name' => 'A']);
        $policy->assertPrepareAllowed('Tag', 'delete', []);
        self::assertTrue($policy->allows('Tag', 'update', ['name' => 'A']));
        self::assertFalse($policy->allows('Tag', 'delete', ['name' => 'A']));
        self::assertFalse($policy->allows('Tag', 'update', ['__proto__' => ['polluted' => true]]));
        self::assertFalse($policy->allows('Tag', 'update', ['nested' => ['__proto__' => ['polluted' => true]]]));

        try {
            $policy->assertPrepareAllowed('Tag', 'update', ['name' => 'A', 'color' => '#fff']);
            self::fail('An unconfigured field must be rejected at prepare.');
        } catch (BadRequestHttpException) {
            // Expected.
        }

        // Membership is not required-field validation: omitting a configured
        // field is left to the existing adapter/business rules.
        $policy->assertPrepareAllowed('Tag', 'create', ['name' => 'A']);

        try {
            $policy->assertPrepareAllowed('Missing', 'update', ['name' => 'A']);
            self::fail('An entity absent from the manifest must be rejected.');
        } catch (BadRequestHttpException) {
            // Expected.
        }

        $disabledFile = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], ['delete' => false]),
        ]));
        $disabled = new AdminConfigurationPolicy($disabledFile);
        try {
            $disabled->assertPrepareAllowed('Tag', 'create', ['name' => 'A']);
            self::fail('A disabled action must be rejected at prepare.');
        } catch (BadRequestHttpException) {
            // Expected.
        }
        try {
            $disabled->assertMutationAllowed('Tag', 'delete', []);
            self::fail('A disabled delete must become a proposal conflict.');
        } catch (ProposalConflictException) {
            // Expected.
        }

        try {
            $policy->assertMutationAllowed('Tag', 'update', ['name' => 'A', 'slug' => 'a']);
            self::fail('A removed/changed field must become a proposal conflict.');
        } catch (ProposalConflictException) {
            // Expected.
        }
    }

    public function testRequiredCreateFieldNotBeingConfiguredIsLeftToTheCatalogActionGate(): void
    {
        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['create' => ['name']], []),
        ]));
        $policy = new AdminConfigurationPolicy($file);

        // Membership alone is not a required-field validator. The catalog and the
        // existing business rules decide whether the action remains usable.
        $policy->assertPrepareAllowed('Tag', 'create', ['name' => 'A']);
        self::assertTrue($policy->allows('Tag', 'create', ['name' => 'A']));
    }

    public function testArtifactIsReloadedOnEachSnapshot(): void
    {
        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name', 'color']], []),
        ]));
        $policy = new AdminConfigurationPolicy($file);
        self::assertTrue($policy->allows('Tag', 'update', ['color' => '#ffffff']));

        $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], []),
        ]));
        self::assertFalse($policy->allows('Tag', 'update', ['color' => '#ffffff']));
        self::assertTrue($policy->allows('Tag', 'update', ['name' => 'A']));
    }

    public function testDepthAndFieldBoundsAreEnforced(): void
    {
        $deep = $this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], []),
        ]);
        $deep['entities'][0]['actions']['update']['fields'][0]['property'] = 'name';
        $deep['entities'][0]['actions']['update']['fields'][0]['nested'] = [[[[[[[[['too-deep']]]]]]]]];
        $file = $this->writeManifest($deep);
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $tooManyFields = [];
        for ($index = 0; $index < AdminConfigurationPolicy::MAX_FIELDS_PER_ACTION + 1; $index++) {
            $tooManyFields[] = 'field'.$index;
        }
        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => $tooManyFields], []),
        ]));
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $file = $this->writeManifest($this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], []),
        ]));
        file_put_contents($file, str_repeat(' ', AdminConfigurationPolicy::DEFAULT_MAX_BYTES + 1));
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param list<array<string, mixed>> $semantic
     */
    public function testNestedRootNullAndExcludedDescendantsAreDeniedButConfiguredLeafNullIsAllowed(): void
    {
        $store = [
            'key' => 'Store',
            'name' => 'Store',
            'actions' => [
                'create' => ['configured' => false, 'fields' => []],
                'update' => ['configured' => true, 'fields' => [
                    ['property' => 'contact', 'nestedPaths' => ['contact.managerName']],
                    ['property' => 'settings', 'nestedPaths' => [
                        'settings.printing.order.enabled',
                        'settings.printing.order.deviceUuid',
                        'settings.printing.order.templateUuid',
                        'settings.printing.order.copies',
                    ]],
                ]],
                'delete' => ['configured' => false, 'fields' => []],
            ],
        ];
        $semantic = [[
            'name' => 'StorePrinting',
            'entity' => 'Store',
            'actions' => ['update'],
            'fields' => [[
                'property' => 'order',
                'path' => 'settings.printing.order',
                'nestedPaths' => [
                    'settings.printing.order.enabled',
                    'settings.printing.order.deviceUuid',
                    'settings.printing.order.templateUuid',
                    'settings.printing.order.copies',
                ],
            ]],
        ]];
        $file = $this->writeManifest($this->manifest(['Store' => $store], $semantic));
        $policy = new AdminConfigurationPolicy($file);

        $policy->assertPrepareAllowed('Store', 'update', ['contact' => ['managerName' => 'Manager']]);
        self::assertTrue($policy->allows('Store', 'update', ['contact' => ['managerName' => null]]));

        foreach ([
            ['contact' => null],
            ['contact' => ['subTitle' => null]],
            ['contact' => ['managerName' => ['nested' => null]]],
        ] as $denied) {
            try {
                $policy->assertPrepareAllowed('Store', 'update', $denied);
                self::fail('Nested root clear or excluded descendant must be denied.');
            } catch (BadRequestHttpException) {
                // Expected.
            }
            try {
                $policy->assertMutationAllowed('Store', 'update', $denied);
                self::fail('Nested root clear or excluded descendant must be a proposal conflict at commit.');
            } catch (ProposalConflictException) {
                // Expected.
            }
        }

        self::assertTrue($policy->allows('StorePrinting', 'update', ['order' => ['deviceUuid' => null]]));
        self::assertTrue($policy->allows('StorePrinting', 'update', ['order' => ['copies' => null]]));
        self::assertFalse($policy->allows('StorePrinting', 'update', ['order' => ['deviceUuid' => ['nested' => null]]]));
    }

    public function testExplicitEmptyOrRelativeNestedPathsAreRejected(): void
    {
        $empty = $this->manifest([
            'Tag' => $this->entity('Tag', ['update' => ['name']], []),
        ]);
        $empty['entities'][0]['actions']['update']['fields'][0]['nestedPaths'] = [];
        $file = $this->writeManifest($empty);
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable());

        $store = [
            'key' => 'Store',
            'name' => 'Store',
            'actions' => [
                'create' => ['configured' => false, 'fields' => []],
                'update' => ['configured' => true, 'fields' => [
                    ['property' => 'settings', 'nestedPaths' => ['settings.printing.order.enabled']],
                ]],
                'delete' => ['configured' => false, 'fields' => []],
            ],
        ];
        $semantic = [[
            'name' => 'StorePrinting',
            'entity' => 'Store',
            'actions' => ['update'],
            'fields' => [[
                'property' => 'order',
                'path' => 'settings.printing.order',
                'nestedPaths' => ['enabled'],
            ]],
        ]];
        $file = $this->writeManifest($this->manifest(['Store' => $store], $semantic));
        self::assertFalse((new AdminConfigurationPolicy($file))->isAvailable(), 'Relative semantic paths are not accepted.');
    }

    public function testSemanticCapabilitiesRequireConfiguredRootPathAndExactNestedLeaves(): void
    {
        $store = [
            'key' => 'Store',
            'name' => 'Store',
            'actions' => [
                'create' => ['configured' => false, 'fields' => []],
                'update' => ['configured' => true, 'fields' => [
                    ['property' => 'settings', 'nestedPaths' => [
                        'settings.printing.order.enabled',
                        'settings.printing.order.copies',
                    ]],
                ]],
                'delete' => ['configured' => false, 'fields' => []],
            ],
        ];
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
        $file = $this->writeManifest($this->manifest(['Store' => $store], $semantic));
        $policy = new AdminConfigurationPolicy($file);

        self::assertTrue($policy->allows('StorePrinting', 'update', ['order' => ['enabled' => true]]));
        self::assertTrue($policy->allows('StorePrinting', 'update', ['order' => ['copies' => 2]]));
        self::assertFalse($policy->allows('StorePrinting', 'update', ['order' => ['unknown' => true]]));
        self::assertFalse($policy->allows('StorePrinting', 'update', ['report' => ['enabled' => true]]));
        self::assertFalse($policy->allows('StorePrinting', 'delete', []));

        $badPath = $semantic;
        $badPath[0]['fields'][0]['path'] = 'settings.printing.missing';
        $file = $this->writeManifest($this->manifest(['Store' => $store], $badPath));
        self::assertFalse((new AdminConfigurationPolicy($file))->allows('StorePrinting', 'update', ['order' => ['enabled' => true]]));

        $copiedAlias = $semantic;
        $copiedAlias[0]['entity'] = 'Product';
        $file = $this->writeManifest($this->manifest(['Store' => $store], $copiedAlias));
        self::assertFalse((new AdminConfigurationPolicy($file))->allows('StorePrinting', 'update', ['order' => ['enabled' => true]]));

        $removedLeaf = $semantic;
        $removedLeaf[0]['fields'][0]['nestedPaths'] = ['settings.printing.order.enabled'];
        $file = $this->writeManifest($this->manifest(['Store' => $store], $removedLeaf));
        self::assertFalse((new AdminConfigurationPolicy($file))->allows('StorePrinting', 'update', ['order' => ['copies' => 2]]));
    }

    private function manifest(array $entities, array $semantic = []): array
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

        return $payload;
    }

    /**
     * @param array<string, list<string>> $writeFields
     * @param array{delete?: bool} $options
     * @return array<string, mixed>
     */
    private function entity(string $name, array $writeFields, array $options): array
    {
        $actions = [];
        foreach (['create', 'update'] as $action) {
            $actions[$action] = [
                'configured' => array_key_exists($action, $writeFields),
                'fields' => array_map(static fn (string $property): array => ['property' => $property], $writeFields[$action] ?? []),
            ];
        }
        $actions['delete'] = ['configured' => ($options['delete'] ?? false) === true, 'fields' => []];

        return ['key' => $name, 'name' => $name, 'actions' => $actions];
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

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): string
    {
        $file = $this->directory.'/manifest.json';
        file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
        return $file;
    }
}
