<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use App\Kernel as CoreKernel;
use App\Core\Controller\RestController;
use NsUltimate\Integration\Backend\Doctrine\EntityExtensionQueryBuilderFactory;
use NsUltimate\Integration\Backend\Doctrine\InheritanceMappingDriver;
use App\Core\Controller\System\EntityController as CoreSystemEntityController;
use NsUltimate\Integration\Backend\System\EntityMetadataController;
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
        // Counter's terminal merge state is application composition, not a core workflow change.
        $container->extension('framework', ['workflows' => ['order' => [
            'places' => ['merged'],
            'transitions' => ['merge' => ['from' => 'confirmed', 'to' => 'merged']],
        ]]]);

        $businessRoot = self::repositoryRoot().'/business/backend';
        $builder->addResource(new GlobResource($businessRoot, '/*/src', true, true));

        // Keep the original migration FQCN and version ordering after the example module rename.
        // Both migrations stay in this path so Doctrine sorts them by timestamp within one namespace.
        $legacyDummyMigrations = $businessRoot.'/Dummy/legacy-migrations';
        if (is_dir($legacyDummyMigrations)) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'NsUltimate\\Business\\Note\\Migrations' => $legacyDummyMigrations,
                ],
            ]);
        }

        $container->import(__DIR__.'/../config/services.yaml');
        // Keep applied migration identities without retaining an assistant backend package.
        $container->extension('doctrine_migrations', ['migrations_paths' => [
            'NsUltimate\\Apps\\AdminAgent\\Backend\\Migrations' => dirname(__DIR__).'/legacy-migrations/admin-agent',
            'NsUltimate\\Integration\\Backend\\Migrations' => dirname(__DIR__).'/migrations',
        ]]);
        $projectTranslationDirectory = dirname(__DIR__).'/translations';
        if (is_dir($projectTranslationDirectory)) {
            $container->extension('framework', [
                'translator' => [
                    // Make project translations take precedence over upstream labels.
                    'default_path' => $projectTranslationDirectory,
                    'paths' => [$this->getProjectDir().'/translations'],
                ],
            ]);
        }
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

        $modules = $this->modules();
        foreach (EntityExtensionRegistry::resources($modules) as $resource) {
            $builder->addResource($resource);
        }
        $extensions = EntityExtensionRegistry::discover($modules);
        foreach (BusinessValidationRegistry::resources($modules) as $resource) {
            $builder->addResource($resource);
        }
        $businessValidation = BusinessValidationRegistry::discover($modules);
        if ($businessValidation['mappings'] !== []) {
            $container->extension('framework', [
                'validation' => [
                    'mapping' => [
                        'paths' => $businessValidation['mappings'],
                    ],
                ],
            ]);
        }
        $services->set(InheritanceMappingDriver::class)
            ->decorate('doctrine.orm.default_metadata_driver')
            ->args([service('.inner'), $extensions['inheritance']]);
        $services->set(EntityExtensionQueryBuilderFactory::class)
            ->args([service('doctrine.orm.entity_manager'), $extensions['ordering']]);
        $services->set(EntityMetadataController::class)
            ->args([service('doctrine.orm.entity_manager'), $extensions['metadata']]);
        $services->set(\NsUltimate\Integration\Backend\Validator\JsonSchemaValidator::class)
            ->args([service(\App\Core\Validator\JsonSchemaValidator::class), $businessValidation['schemas']]);

        foreach ($modules as $module) {
            $translationDirectory = $module['path'].'/translations';
            if (is_dir($translationDirectory)) {
                $container->extension('framework', [
                    'translator' => [
                        'paths' => [$translationDirectory],
                    ],
                ]);
            }

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

        $modules = $this->modules();
        foreach ($modules as $module) {
            $controllerDirectory = $module['path'].'/src/Controller';
            $routesFile = $module['path'].'/config/routes.yaml';
            if (is_file($routesFile)) {
                RouteImportGuard::import($routes, $routesFile);
            } elseif (is_dir($controllerDirectory)) {
                RouteImportGuard::import($routes, $controllerDirectory, 'attribute');
            }
        }

        ControllerRouteOverride::apply(
            $routes,
            [
                ...EntityExtensionRegistry::discover($modules)['controllers'],
                CoreSystemEntityController::class => EntityMetadataController::class,
            ],
            [...EntityExtensionRegistry::resources($modules), ...BusinessValidationRegistry::resources($modules)],
        );
    }

    /** @return list<array{name: string, namespace: string, path: string, doctrine_alias: string, migration_namespace: string}> */
    private function modules(): array
    {
        return ModuleRegistry::discover(self::repositoryRoot().'/business/backend');
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
