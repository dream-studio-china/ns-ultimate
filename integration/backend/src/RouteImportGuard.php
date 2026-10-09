<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use LogicException;
use ReflectionProperty;
use Symfony\Component\Routing\Loader\Configurator\ImportConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouteCollection;

final class RouteImportGuard
{
    public static function import(
        RoutingConfigurator $configurator,
        string $resource,
        ?string $type = null,
    ): void {
        $collectionProperty = new ReflectionProperty(RoutingConfigurator::class, 'collection');
        $existing = $collectionProperty->getValue($configurator);
        if (!$existing instanceof RouteCollection) {
            throw new LogicException('Unable to inspect the configured route collection.');
        }

        $import = $configurator->import($resource, $type);
        if (!$import instanceof ImportConfigurator) {
            throw new LogicException(sprintf('Unable to inspect routes imported from "%s".', $resource));
        }

        $importedProperty = new ReflectionProperty(ImportConfigurator::class, 'route');
        $incoming = $importedProperty->getValue($import);
        if (!$incoming instanceof RouteCollection) {
            throw new LogicException(sprintf('Imported resource "%s" is not a route collection.', $resource));
        }

        self::assertNoCollisions($existing, $incoming, $resource);

        // ImportConfigurator merges its routes into the parent collection on destruction.
        unset($import);
    }

    public static function assertNoCollisions(
        RouteCollection $existing,
        RouteCollection $incoming,
        string $resource,
    ): void {
        $collisions = array_intersect(array_keys($existing->all()), array_keys($incoming->all()));
        if ($collisions !== []) {
            $details = [];
            foreach ($collisions as $name) {
                $details[] = sprintf(
                    '%s (%s vs %s)',
                    $name,
                    $existing->get($name)->getPath(),
                    $incoming->get($name)->getPath(),
                );
            }
            throw new LogicException(sprintf(
                'Route resource "%s" defines route name(s) already registered: %s.',
                $resource,
                implode(', ', $details),
            ));
        }
    }
}
