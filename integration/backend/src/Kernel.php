<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use App\Kernel as CoreKernel;
use App\Core\Controller\RestController;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class Kernel extends CoreKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(
            new RemoveBusinessControllerRouteTagsPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            99,
        );
    }

    public function getProjectDir(): string
    {
        return self::repositoryRoot().'/core/crud-skeleton';
    }

    public function getCacheDir(): string
    {
        return self::repositoryRoot().'/var/cache/backend/'.$this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return self::repositoryRoot().'/var/log/backend';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = self::repositoryRoot().'/core/crud-skeleton/config';
        $container->import($configDir.'/{packages}/*.{php,yaml}');
        $container->import($configDir.'/{packages}/'.$this->getEnvironment().'/*.{php,yaml}');
        $container->import($configDir.'/services.yaml');
        $container->import($configDir.'/{services}_'.$this->getEnvironment().'.yaml');

        $businessRoot = self::repositoryRoot().'/business/backend';
        $builder->addResource(new GlobResource($businessRoot, '/*/src', true, true));

        // Keep the original migration FQCN discoverable after the example module rename.
        $legacyDummyMigrations = $businessRoot.'/Dummy/legacy-migrations';
        if (is_dir($legacyDummyMigrations)) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'NsUltimate\\Business\\Note\\Migrations' => $legacyDummyMigrations,
                ],
            ]);
        }

        $container->import(__DIR__.'/../config/services.yaml');
        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        $services->instanceof(RestController::class)
            ->call('setRequestStack', [service('request_stack')])
            ->call('setSerializer', [service('serializer')])
            ->call('setTranslator', [service('translator')])
            ->call('setExpansionMetadata', [service('App\\Core\\Serializer\\ExpansionMetadata')])
            ->call('setServiceContainer', [service('service_container')]);

        foreach ($this->modules() as $module) {
            $services->load($module['namespace'], $module['path'].'/src/')
                ->exclude([
                    $module['path'].'/src/Entity/',
                    $module['path'].'/src/Migrations/',
                ]);

            $servicesFile = $module['path'].'/config/services.yaml';
            if (is_file($servicesFile)) {
                $container->import($servicesFile);
            }

            $entityDirectory = $module['path'].'/src/Entity';
            if (is_dir($entityDirectory)) {
                $container->extension('doctrine', [
                    'orm' => [
                        'mappings' => [
                            $module['doctrine_alias'] => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => $entityDirectory,
                                'prefix' => rtrim($module['namespace'], '\\').'\\Entity',
                                'alias' => $module['doctrine_alias'],
                            ],
                        ],
                    ],
                ]);
            }

            $migrationDirectory = $module['path'].'/migrations';
            if (is_dir($migrationDirectory)) {
                $container->extension('doctrine_migrations', [
                    'migrations_paths' => [
                        $module['migration_namespace'] => $migrationDirectory,
                    ],
                ]);
            }
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = self::repositoryRoot().'/core/crud-skeleton/config';
        RouteImportGuard::import($routes, $configDir.'/{routes}/'.$this->getEnvironment().'/*.{php,yaml}');
        RouteImportGuard::import($routes, $configDir.'/routes/*.{php,yaml}');
        RouteImportGuard::import($routes, $configDir.'/routes.yaml');

        foreach ($this->modules() as $module) {
            $controllerDirectory = $module['path'].'/src/Controller';
            $routesFile = $module['path'].'/config/routes.yaml';
            if (is_file($routesFile)) {
                RouteImportGuard::import($routes, $routesFile);
            } elseif (is_dir($controllerDirectory)) {
                RouteImportGuard::import($routes, $controllerDirectory, 'attribute');
            }
        }
    }

    /** @return array<string, array{namespace: string, path: string, doctrine_alias: string, migration_namespace: string}> */
    private function modules(): array
    {
        return ModuleRegistry::discover(self::repositoryRoot().'/business/backend');
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
