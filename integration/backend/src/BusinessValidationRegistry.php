<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\ResourceInterface;

/** Discovers business-owned Symfony validation mappings and JSON Schemas. */
final class BusinessValidationRegistry
{
    /**
     * @param array<array{name: string, path: string}> $modules
     * @return array{mappings: list<string>, schemas: array<string, string>}
     */
    public static function discover(array $modules): array
    {
        $result = ['mappings' => [], 'schemas' => []];
        foreach ($modules as $module) {
            $mapping = $module['path'].'/config/validation.yaml';
            if (is_file($mapping)) {
                $result['mappings'][] = $mapping;
            }

            $schemaDirectory = $module['path'].'/resources/json-schema';
            foreach (glob($schemaDirectory.'/*.json') ?: [] as $schemaPath) {
                $name = basename($schemaPath, '.json');
                if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) !== 1) {
                    throw new \LogicException(sprintf('Invalid JSON Schema filename "%s".', $schemaPath));
                }
                $schemaName = $module['name'].'/'.$name;
                if (isset($result['schemas'][$schemaName])) {
                    throw new \LogicException(sprintf('JSON Schema "%s" is registered more than once.', $schemaName));
                }
                $schema = json_decode((string) file_get_contents($schemaPath), flags: JSON_THROW_ON_ERROR);
                if (!$schema instanceof \stdClass) {
                    throw new \LogicException(sprintf('JSON Schema "%s" must contain a JSON object.', $schemaName));
                }
                $result['schemas'][$schemaName] = $schemaPath;
            }
        }

        return $result;
    }

    /**
     * @param array<array{name: string, path: string}> $modules
     * @return list<ResourceInterface>
     */
    public static function resources(array $modules): array
    {
        $resources = [];
        foreach ($modules as $module) {
            $mapping = $module['path'].'/config/validation.yaml';
            $resources[] = new FileExistenceResource($mapping);
            if (is_file($mapping)) {
                $resources[] = new FileResource($mapping);
            }

            $resources[] = new FileExistenceResource($module['path'].'/resources/json-schema');
            $resources[] = new GlobResource($module['path'], '/resources/json-schema/*.json', false);
            foreach (glob($module['path'].'/resources/json-schema/*.json') ?: [] as $schemaPath) {
                $resources[] = new FileResource($schemaPath);
            }
        }

        return $resources;
    }
}
