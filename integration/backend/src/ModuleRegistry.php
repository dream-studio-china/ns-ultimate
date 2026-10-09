<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use RuntimeException;

final class ModuleRegistry
{
    /** @return list<array{name: string, namespace: string, path: string, doctrine_alias: string, migration_namespace: string}> */
    public static function discover(string $businessRoot): array
    {
        $businessRoot = realpath($businessRoot) ?: null;
        if ($businessRoot === null) {
            return [];
        }

        $sourceDirectories = glob($businessRoot.'/*/src', GLOB_ONLYDIR) ?: [];
        sort($sourceDirectories, SORT_STRING);

        $modules = [];
        $seen = [
            'namespace' => [],
            'doctrine_alias' => [],
            'migration_namespace' => [],
        ];

        foreach ($sourceDirectories as $sourceDirectory) {
            $modulePath = realpath(dirname($sourceDirectory));
            if ($modulePath === false || dirname($modulePath) !== $businessRoot) {
                throw new RuntimeException(sprintf('Business module path "%s" must be a direct child of business/backend.', $sourceDirectory));
            }

            $name = basename($modulePath);
            if (preg_match('/^[A-Z][a-zA-Z0-9]*$/D', $name) !== 1) {
                throw new RuntimeException(sprintf('Business module directory "%s" must use singular PascalCase.', $name));
            }

            $namespacePart = $name;
            $module = [
                'name' => $name,
                'namespace' => 'NsUltimate\\Business\\'.$namespacePart.'\\',
                'path' => $modulePath,
                'doctrine_alias' => 'Business'.$namespacePart,
                'migration_namespace' => 'NsUltimate\\Business\\'.$namespacePart.'\\Migrations',
            ];

            foreach (['namespace', 'doctrine_alias', 'migration_namespace'] as $key) {
                if (isset($seen[$key][$module[$key]])) {
                    throw new RuntimeException(sprintf('Business modules produce duplicate "%s" value "%s".', $key, $module[$key]));
                }
                $seen[$key][$module[$key]] = true;
            }

            $modules[] = $module;
        }

        return $modules;
    }
}
