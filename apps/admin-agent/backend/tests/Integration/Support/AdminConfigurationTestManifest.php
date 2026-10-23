<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support;

use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityFieldPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilitySchemas;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Service\CounterWriteService;

/**
 * Test-only synthetic manifest for legacy adapter safety suites.
 *
 * Production always uses the generated effective-configuration artifact. These
 * focused suites predate the negative gate and intentionally exercise the
 * previously approved legacy descriptors even where the current composed config
 * no longer exposes them.
 */
final class AdminConfigurationTestManifest
{
    private static bool $hadEnvironment = false;
    private static string|false $previousEnvironment = false;
    private static ?string $installedFile = null;

    public static function install(): string
    {
        self::$previousEnvironment = getenv('ADMIN_AGENT_MANIFEST_PATH');
        self::$hadEnvironment = self::$previousEnvironment !== false;

        $file = self::writeSyntheticManifest();
        self::$installedFile = $file;
        putenv('ADMIN_AGENT_MANIFEST_PATH='.$file);
        $_ENV['ADMIN_AGENT_MANIFEST_PATH'] = $file;
        $_SERVER['ADMIN_AGENT_MANIFEST_PATH'] = $file;

        return $file;
    }

    public static function legacyPolicy(): AdminConfigurationPolicy
    {
        return new AdminConfigurationPolicy(self::$installedFile ?? self::writeSyntheticManifest());
    }

    private static function writeSyntheticManifest(): string
    {
        $file = sys_get_temp_dir().'/admin-agent-legacy-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($file, json_encode(self::build(), JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($file, 0600);

        return $file;
    }

    public static function realPolicy(): AdminConfigurationPolicy
    {
        return new AdminConfigurationPolicy(dirname(__DIR__, 6).'/var/admin-agent/admin-manifest.json');
    }

    public static function restore(string $file): void
    {
        @unlink($file);
        if (self::$installedFile === $file) self::$installedFile = null;
        if (self::$hadEnvironment) {
            putenv('ADMIN_AGENT_MANIFEST_PATH='.self::$previousEnvironment);
            $_ENV['ADMIN_AGENT_MANIFEST_PATH'] = self::$previousEnvironment;
            $_SERVER['ADMIN_AGENT_MANIFEST_PATH'] = self::$previousEnvironment;
        } else {
            putenv('ADMIN_AGENT_MANIFEST_PATH');
            unset($_ENV['ADMIN_AGENT_MANIFEST_PATH'], $_SERVER['ADMIN_AGENT_MANIFEST_PATH']);
        }
    }

    /** @return array<string, mixed> */
    public static function build(): array
    {
        $entities = [];
        $printingCases = ['order', 'perItem', 'filteredList', 'fullList', 'settlement'];
        $printingFields = ['enabled', 'deviceUuid', 'templateUuid', 'copies'];
        foreach (CapabilitySchemas::ENTITY_ORDER as $entity) {
            $descriptor = match ($entity) {
                'Tag' => CapabilityFieldPolicy::descriptor('Tag', TagWriteService::fieldSchema(...)),
                'Counter' => CapabilityFieldPolicy::descriptor('Counter', CounterWriteService::fieldSchema(...)),
                default => CapabilitySchemas::forEntity($entity),
            };
            if (!is_array($descriptor)) continue;

            $actions = [];
            foreach (AdminConfigurationPolicy::ACTIONS as $action) {
                $configured = in_array($action, $descriptor['actions'], true);
                $fields = $action === 'delete'
                    ? []
                    : array_keys($descriptor['actionFields'][$action] ?? []);
                $projectedFields = [];
                foreach ($fields as $property) {
                    $nestedPaths = [];
                    if ($action === 'update' && $property === 'settings' && $entity === 'Store') {
                        foreach ($printingCases as $case) {
                            foreach ($printingFields as $field) {
                                $nestedPaths[] = 'settings.printing.'.$case.'.'.$field;
                            }
                        }
                    }
                    if ($action === 'update' && $property === 'settings' && $entity === 'Product') {
                        $nestedPaths[] = 'settings.kitchenPrinterEnable';
                    }
                    $projectedFields[] = $nestedPaths === []
                        ? ['property' => $property]
                        : ['property' => $property, 'nestedPaths' => $nestedPaths];
                }
                if ($action === 'update' && $entity === 'Store' && !in_array('settings', $fields, true)) {
                    $nestedPaths = [];
                    foreach ($printingCases as $case) {
                        foreach ($printingFields as $field) {
                            $nestedPaths[] = 'settings.printing.'.$case.'.'.$field;
                        }
                    }
                    $projectedFields[] = ['property' => 'settings', 'nestedPaths' => $nestedPaths];
                }
                if ($action === 'update' && $entity === 'Product' && !in_array('settings', $fields, true)) {
                    $projectedFields[] = ['property' => 'settings', 'nestedPaths' => ['settings.kitchenPrinterEnable']];
                }
                $actions[$action] = ['configured' => $configured, 'fields' => $projectedFields];
            }
            $entities[] = [
                'key' => $descriptor['entity'],
                'name' => $descriptor['entity'],
                'actions' => $actions,
            ];
        }

        $semantic = [];
        foreach (['StorePrinting' => 'Store', 'ProductPrinting' => 'Product'] as $name => $source) {
            $descriptor = CapabilitySchemas::forEntity($name);
            if (!is_array($descriptor)) continue;
            $fields = [];
            foreach (array_keys($descriptor['actionFields']['update'] ?? []) as $property) {
                if ($name === 'StorePrinting') {
                    $path = 'settings.printing.'.$property;
                    $nestedPaths = [];
                    foreach ($printingFields as $field) {
                        $nestedPaths[] = $path.'.'.$field;
                    }
                } else {
                    $path = 'settings.'.$property;
                    $nestedPaths = [];
                }
                $fields[] = $nestedPaths === []
                    ? ['property' => $property, 'path' => $path]
                    : ['property' => $property, 'path' => $path, 'nestedPaths' => $nestedPaths];
            }
            $semantic[] = [
                'name' => $name,
                'entity' => $source,
                'actions' => ['update'],
                'fields' => $fields,
            ];
        }

        usort($entities, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        usort($semantic, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $payload = [
            'schemaVersion' => AdminConfigurationPolicy::SCHEMA_VERSION,
            'source' => AdminConfigurationPolicy::SOURCE,
            'entities' => $entities,
            'semanticCapabilities' => $semantic,
        ];
        $payload['revision'] = self::revisionOf($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function revisionOf(array $payload): string
    {
        unset($payload['revision']);
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }
}
