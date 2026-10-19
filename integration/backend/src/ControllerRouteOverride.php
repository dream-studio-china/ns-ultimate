<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Config\Resource\ResourceInterface;

/** Applies explicit controller substitutions to already-imported routes. */
final class ControllerRouteOverride
{
    /**
     * @param array<class-string, class-string> $overrides Original controller => replacement controller
     * @param list<ResourceInterface> $resources Configuration resources affecting route overrides
     */
    public static function apply(RoutingConfigurator $configurator, array $overrides, array $resources = []): void
    {
        $collection = (new \ReflectionProperty(RoutingConfigurator::class, 'collection'))->getValue($configurator);
        if (!$collection instanceof RouteCollection) {
            throw new \LogicException('Unable to inspect the composed route collection.');
        }

        foreach ($resources as $resource) {
            $collection->addResource($resource);
        }

        foreach ($collection as $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }

            foreach ($overrides as $original => $replacement) {
                $prefix = $original.'::';
                if (str_starts_with($controller, $prefix)) {
                    $route->setDefault('_controller', $replacement.substr($controller, strlen($original)));
                    break;
                }
            }
        }
    }
}
