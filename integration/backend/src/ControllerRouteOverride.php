<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouteCollection;

/** Applies explicit controller substitutions to already-imported routes. */
final class ControllerRouteOverride
{
    /**
     * @param array<class-string, class-string> $overrides Original controller => replacement controller
     */
    public static function apply(RoutingConfigurator $configurator, array $overrides): void
    {
        $collection = (new \ReflectionProperty(RoutingConfigurator::class, 'collection'))->getValue($configurator);
        if (!$collection instanceof RouteCollection) {
            throw new \LogicException('Unable to inspect the composed route collection.');
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
