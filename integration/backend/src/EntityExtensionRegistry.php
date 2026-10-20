<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

/** Loads explicit entity composition declarations owned by business modules. */
final class EntityExtensionRegistry
{
    /**
     * @param array<array{path: string}> $modules
     * @return array{
     *     inheritance: array<class-string, array{column: string, map: array<string, class-string>}>,
     *     ordering: array<class-string, array<string, class-string>>,
     *     metadata: array<class-string, class-string>,
     *     controllers: array<class-string, class-string>
     * }
     */
    public static function discover(array $modules): array
    {
        $result = ['inheritance' => [], 'ordering' => [], 'metadata' => [], 'controllers' => []];
        foreach ($modules as $module) {
            $file = $module['path'].'/config/entity_extensions.yaml';
            if (!is_file($file)) {
                continue;
            }

            $config = Yaml::parseFile($file) ?? [];
            self::assertMap($config, $file);
            self::assertKeys($config, ['entities', 'controllers'], $file);
            $entities = $config['entities'] ?? [];
            $controllers = $config['controllers'] ?? [];
            self::assertMap($entities, $file);
            self::assertMap($controllers, $file);

            foreach ($entities as $root => $extension) {
                if (isset($result['inheritance'][$root])) {
                    throw new \LogicException(sprintf('Entity extension "%s" is declared more than once (%s).', $root, $file));
                }
                self::validateEntity($root, $extension, $file);
                $result['inheritance'][$root] = ['column' => $extension['column'], 'map' => $extension['map']];
                if (!empty($extension['order_fields'])) {
                    $result['ordering'][$root] = $extension['order_fields'];
                }
                if (isset($extension['metadata_class'])) {
                    $result['metadata'][$root] = $extension['metadata_class'];
                }
            }

            foreach ($controllers as $original => $replacement) {
                if (isset($result['controllers'][$original])) {
                    throw new \LogicException(sprintf('Controller override "%s" is declared more than once (%s).', $original, $file));
                }
                self::validateController($original, $replacement, $file);
                $result['controllers'][$original] = $replacement;
            }
        }

        return $result;
    }

    /**
     * @param array<array{path: string}> $modules
     * @return list<ResourceInterface>
     */
    public static function resources(array $modules): array
    {
        $resources = [];
        foreach ($modules as $module) {
            $file = $module['path'].'/config/entity_extensions.yaml';
            $resources[] = new FileExistenceResource($file);
            if (is_file($file)) {
                $resources[] = new FileResource($file);
            }
        }

        return $resources;
    }

    private static function validateEntity(string $root, mixed $extension, string $file): void
    {
        self::assertEntity($root, $file);
        self::assertMap($extension, $file);
        self::assertKeys($extension, ['column', 'map', 'order_fields', 'metadata_class'], $file);
        if (!is_string($extension['column'] ?? null) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $extension['column'])) {
            throw new \LogicException(sprintf('Entity "%s" requires a valid discriminator column (%s).', $root, $file));
        }
        $map = $extension['map'] ?? null;
        self::assertMap($map, $file);
        if (reset($map) !== $root) {
            throw new \LogicException(sprintf('Discriminator map for "%s" must start with its root class (%s).', $root, $file));
        }

        $seen = [];
        foreach ($map as $value => $class) {
            if (strlen($value) > 32 || $value === '' || !is_string($class)) {
                throw new \LogicException(sprintf('Invalid discriminator map for "%s" (%s).', $root, $file));
            }
            self::assertEntity($class, $file);
            if (!is_a($class, $root, true) || isset($seen[$class])) {
                throw new \LogicException(sprintf('Discriminator class "%s" must uniquely extend "%s" (%s).', $class, $root, $file));
            }
            $seen[$class] = true;
        }

        if (isset($extension['metadata_class'])) {
            $metadataClass = $extension['metadata_class'];
            if (!is_string($metadataClass) || !in_array($metadataClass, $map, true)) {
                throw new \LogicException(sprintf('Metadata class for "%s" must be one of its discriminator-mapped classes (%s).', $root, $file));
            }
        }

        $fields = $extension['order_fields'] ?? [];
        self::assertMap($fields, $file);
        foreach ($fields as $field => $class) {
            if (!is_string($class) || !class_exists($class) || !in_array($class, $map, true) || $class === $root) {
                throw new \LogicException(sprintf('Ordering field "%s" must target a mapped subtype of "%s" (%s).', $field, $root, $file));
            }
            $reflection = new \ReflectionClass($class);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field)
                || !$reflection->hasProperty($field)
                || $reflection->getProperty($field)->getAttributes(ORM\Column::class) === []) {
                throw new \LogicException(sprintf('Ordering field "%s" must be a mapped scalar property of "%s" (%s).', $field, $class, $file));
            }
        }
    }

    private static function assertEntity(string $class, string $file): void
    {
        if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()
            || (new \ReflectionClass($class))->getAttributes(ORM\Entity::class) === []) {
            throw new \LogicException(sprintf('Extension class "%s" must be a concrete Doctrine entity (%s).', $class, $file));
        }
    }

    private static function validateController(string $original, mixed $replacement, string $file): void
    {
        if (!class_exists($original) || !is_string($replacement) || !class_exists($replacement)) {
            throw new \LogicException(sprintf('Controller override "%s" must reference existing classes (%s).', $original, $file));
        }
        $target = new \ReflectionClass($replacement);
        if (!$target->isInstantiable()) {
            throw new \LogicException(sprintf('Replacement controller "%s" must be concrete (%s).', $replacement, $file));
        }
        foreach ((new \ReflectionClass($original))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Route::class) !== []
                && (!$target->hasMethod($method->name) || !$target->getMethod($method->name)->isPublic())) {
                throw new \LogicException(sprintf('Replacement controller "%s" must expose action "%s" (%s).', $replacement, $method->name, $file));
            }
        }
    }

    private static function assertMap(mixed $value, string $file): void
    {
        if (!is_array($value) || array_filter(array_keys($value), 'is_int') !== []) {
            throw new \LogicException(sprintf('Entity extension configuration must contain named mappings (%s).', $file));
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private static function assertKeys(array $value, array $allowed, string $file): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new \LogicException(sprintf('Unknown entity extension configuration key (%s).', $file));
        }
    }
}
