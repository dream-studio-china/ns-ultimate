<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use App\Store\Controller\Manage\ProductController;
use NsUltimate\Business\Store\Adapter\ManageProductController;
use NsUltimate\Integration\Backend\ControllerRouteOverride;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class ControllerRouteOverrideTest extends TestCase
{
    public function testOverridePreservesRouteMetadataAndTracksConfigurationResources(): void
    {
        $route = new Route('/manage/products', ['_controller' => ProductController::class.'::listAction'], [], [], '', [], ['GET']);
        $routes = new RouteCollection();
        $routes->add('manage-products-list', $route);
        $configurator = new RoutingConfigurator($routes, new PhpFileLoader(new FileLocator()), __FILE__, __FILE__);
        $resource = new FileExistenceResource(__DIR__.'/nonexistent-extension-config.yaml');

        ControllerRouteOverride::apply($configurator, [ProductController::class => ManageProductController::class], [$resource]);

        self::assertSame($route, $routes->get('manage-products-list'));
        self::assertSame(ManageProductController::class.'::listAction', $route->getDefault('_controller'));
        self::assertSame('/manage/products', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertContains($resource, $routes->getResources());
    }
}
