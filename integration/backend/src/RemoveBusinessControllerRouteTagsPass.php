<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RemoveBusinessControllerRouteTagsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (
                is_string($class)
                && str_starts_with($class, 'NsUltimate\\Business\\')
                && $definition->hasTag('routing.controller')
            ) {
                $definition->clearTag('routing.controller');
            }
        }
    }
}
