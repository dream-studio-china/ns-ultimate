<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use App\Common\Controller\Manage\ContentController;
use App\Common\Entity\Content as CoreContent;
use App\Store\Controller\Manage\ProductController;
use App\Store\Entity\Product as CoreProduct;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use NsUltimate\Business\Common\Adapter\ManageContentController;
use NsUltimate\Business\Common\Entity\Content;
use NsUltimate\Business\Store\Adapter\ManageProductController;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Integration\Backend\EntityExtensionRegistry;
use NsUltimate\Integration\Backend\ModuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Yaml\Yaml;

final class EntityExtensionRegistryTest extends TestCase
{
    private string $businessRoot;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        $this->businessRoot = sys_get_temp_dir().'/ns-ultimate-entity-extensions-'.bin2hex(random_bytes(8));
        mkdir($this->businessRoot);
        $this->directories[] = $this->businessRoot;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (array_reverse($this->directories) as $directory) {
            rmdir($directory);
        }
    }

    public function testNoModulesReturnsEmptyMaps(): void
    {
        self::assertSame(self::emptyMaps(), EntityExtensionRegistry::discover([]));
    }

    public function testNoModulesReturnsNoResources(): void
    {
        self::assertSame([], EntityExtensionRegistry::resources([]));
    }

    public function testResourcesTrackEveryOptionalConfigurationAndExistingFile(): void
    {
        $this->createModule('Common');
        $this->createModule('Store', []);
        $modules = ModuleRegistry::discover($this->businessRoot);

        $resources = EntityExtensionRegistry::resources($modules);

        self::assertTrue(array_is_list($resources));
        self::assertCount(3, $resources);
        foreach ($resources as $resource) {
            self::assertInstanceOf(ResourceInterface::class, $resource);
        }
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertSame($modules[0]['path'].'/config/entity_extensions.yaml', $resources[0]->getResource());
        self::assertInstanceOf(FileExistenceResource::class, $resources[1]);
        self::assertSame($modules[1]['path'].'/config/entity_extensions.yaml', $resources[1]->getResource());
        self::assertInstanceOf(FileResource::class, $resources[2]);
        self::assertSame($modules[1]['path'].'/config/entity_extensions.yaml', $resources[2]->getResource());
    }

    public function testCreatingMissingConfigurationInvalidatesExistenceResource(): void
    {
        $this->createModule('Store');
        $modules = ModuleRegistry::discover($this->businessRoot);
        $resources = EntityExtensionRegistry::resources($modules);
        self::assertCount(1, $resources);
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertTrue($resources[0]->isFresh(1700000000));

        $directory = $modules[0]['path'].'/config';
        mkdir($directory);
        $this->directories[] = $directory;
        $file = $directory.'/entity_extensions.yaml';
        $this->files[] = $file;
        file_put_contents($file, "entities: {}\n");
        clearstatcache(true, $file);

        self::assertFalse($resources[0]->isFresh(1700000000));
    }

    public function testRemovingConfigurationInvalidatesExistenceResource(): void
    {
        $this->createModule('Store', []);
        $modules = ModuleRegistry::discover($this->businessRoot);
        $resources = EntityExtensionRegistry::resources($modules);
        self::assertCount(2, $resources);
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertTrue($resources[0]->isFresh(1700000000));

        $file = $modules[0]['path'].'/config/entity_extensions.yaml';
        unlink($file);
        clearstatcache(true, $file);

        self::assertFalse($resources[0]->isFresh(1700000000));
    }

    public function testModifyingConfigurationInvalidatesFileResource(): void
    {
        $this->createModule('Store', []);
        $modules = ModuleRegistry::discover($this->businessRoot);
        $file = $modules[0]['path'].'/config/entity_extensions.yaml';
        $timestamp = 1700000000;
        self::assertTrue(touch($file, $timestamp - 10));
        clearstatcache(true, $file);
        $resources = EntityExtensionRegistry::resources($modules);
        self::assertCount(2, $resources);
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertInstanceOf(FileResource::class, $resources[1]);
        self::assertTrue($resources[1]->isFresh($timestamp));

        file_put_contents($file, "entities: {}\ncontrollers: {}\n");
        self::assertTrue(touch($file, $timestamp + 10));
        clearstatcache(true, $file);

        self::assertFalse($resources[1]->isFresh($timestamp));
        self::assertTrue($resources[0]->isFresh($timestamp));
    }

    public function testScalarYamlConfigurationIsRejected(): void
    {
        $this->createModule('Store', "true\n");

        $this->expectException(LogicException::class);
        $this->discover();
    }

    public function testModulesWithoutConfigurationReturnEmptyMaps(): void
    {
        $this->createModule('Common');
        $this->createModule('Store');

        self::assertSame(self::emptyMaps(), $this->discover());
    }

    public function testEmptyConfigurationReturnsEmptyMaps(): void
    {
        $this->createModule('Common', '');
        $this->createModule('Store', "# No entity extensions.\n");

        self::assertSame(self::emptyMaps(), $this->discover());
    }

    public function testExplicitEmptyMapsAreAccepted(): void
    {
        $this->createModule('Store', ['entities' => [], 'controllers' => []]);

        self::assertSame(self::emptyMaps(), $this->discover());
    }

    public function testIndependentModulesMergeEntityOrderingAndControllerMaps(): void
    {
        $this->createModule('Common', [
            'entities' => [CoreContent::class => self::contentExtension()],
            'controllers' => [ContentController::class => ManageContentController::class],
        ]);
        $this->createModule('Store', [
            'entities' => [CoreProduct::class => self::productExtension()],
            'controllers' => [ProductController::class => ManageProductController::class],
        ]);

        self::assertSame([
            'inheritance' => [
                CoreContent::class => ['column' => 'content_type', 'map' => ['core' => CoreContent::class, 'common' => Content::class]],
                CoreProduct::class => ['column' => 'product_type', 'map' => ['core' => CoreProduct::class, 'store' => Product::class]],
            ],
            'ordering' => [
                CoreContent::class => ['sortOrder' => Content::class],
                CoreProduct::class => ['sortOrder' => Product::class],
            ],
            'controllers' => [
                ContentController::class => ManageContentController::class,
                ProductController::class => ManageProductController::class,
            ],
        ], $this->discover());
    }

    public function testOrderingAndControllerDeclarationsAreOptional(): void
    {
        $extension = self::productExtension();
        unset($extension['order_fields']);
        $this->createModule('Store', ['entities' => [CoreProduct::class => $extension]]);

        self::assertSame([
            'inheritance' => [CoreProduct::class => $extension],
            'ordering' => [],
            'controllers' => [],
        ], $this->discover());
    }

    public function testDuplicateRootDeclarationsAcrossModulesFailEvenWhenIdentical(): void
    {
        $configuration = ['entities' => [CoreProduct::class => self::productExtension()]];
        $this->createModule('Common', $configuration);
        $this->createModule('Store', $configuration);

        $this->expectException(LogicException::class);
        $this->discover();
    }

    public function testDuplicateOriginalControllersAcrossModulesFailEvenWhenIdentical(): void
    {
        $configuration = ['controllers' => [ProductController::class => ManageProductController::class]];
        $this->createModule('Common', $configuration);
        $this->createModule('Store', $configuration);

        $this->expectException(LogicException::class);
        $this->discover();
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(array $configuration): void
    {
        $this->createModule('Store', $configuration);

        $this->expectException(LogicException::class);
        $this->discover();
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'unknown top-level key' => [['entity' => []]];
        yield 'entities must be a map' => [['entities' => 'invalid']];
        yield 'entities cannot be a list' => [['entities' => [CoreProduct::class]]];
        yield 'entity declaration must be a map' => [['entities' => [CoreProduct::class => 'invalid']]];
        yield 'controllers must be a map' => [['controllers' => 'invalid']];
        yield 'controller replacement must be a class name' => [['controllers' => [ProductController::class => []]]];
        yield 'original controller must exist' => [['controllers' => ['Missing\\Controller' => ManageProductController::class]]];
        yield 'replacement controller must exist' => [['controllers' => [ProductController::class => 'Missing\\Controller']]];
        yield 'replacement must expose original routed actions' => [['controllers' => [ProductController::class => IncompleteExtensionController::class]]];

        $valid = self::productExtension();
        foreach ([
            'unknown entity key' => [...$valid, 'order_field' => []],
            'empty discriminator column' => [...$valid, 'column' => ''],
            'non-string discriminator column' => [...$valid, 'column' => []],
            'map must be a map' => [...$valid, 'map' => 'invalid'],
            'empty discriminator map' => [...$valid, 'map' => []],
            'missing root entry' => [...$valid, 'map' => ['store' => Product::class]],
            'root entry must be first' => [...$valid, 'map' => ['store' => Product::class, 'core' => CoreProduct::class]],
            'duplicate concrete classes' => [...$valid, 'map' => ['core' => CoreProduct::class, 'store' => Product::class, 'duplicate' => Product::class]],
            'unknown subtype' => [...$valid, 'map' => ['core' => CoreProduct::class, 'store' => 'Missing\\Entity']],
            'unrelated entity subtype' => [...$valid, 'map' => ['core' => CoreProduct::class, 'common' => Content::class]],
            'non-entity subtype' => [...$valid, 'map' => ['core' => CoreProduct::class, 'store' => NonEntityProductExtension::class]],
            'order fields must be a map' => [...$valid, 'order_fields' => 'sortOrder'],
            'typo ordering field' => [...$valid, 'order_fields' => ['sortOder' => Product::class]],
            'ordering subtype must be in discriminator map' => [...$valid, 'order_fields' => ['sortOrder' => Content::class]],
            'ordering field mapped on wrong subtype' => [...$valid, 'order_fields' => ['sortOrder' => CoreProduct::class]],
            'ordering target must be a class name' => [...$valid, 'order_fields' => ['sortOrder' => []]],
            'unmapped ordering property' => [
                ...$valid,
                'map' => ['core' => CoreProduct::class, 'store' => UnmappedOrderProductExtension::class],
                'order_fields' => ['unmappedOrder' => UnmappedOrderProductExtension::class],
            ],
        ] as $name => $extension) {
            yield $name => [['entities' => [CoreProduct::class => $extension]]];
        }

        $missingColumn = $valid;
        unset($missingColumn['column']);
        yield 'missing discriminator column' => [['entities' => [CoreProduct::class => $missingColumn]]];
        $missingMap = $valid;
        unset($missingMap['map']);
        yield 'missing discriminator map' => [['entities' => [CoreProduct::class => $missingMap]]];
        yield 'unknown root class' => [['entities' => ['Missing\\Entity' => $valid]]];
        yield 'non-entity root class' => [['entities' => [IncompleteExtensionController::class => [
            'column' => 'type', 'map' => ['core' => IncompleteExtensionController::class],
        ]]]];
    }

    private static function productExtension(): array
    {
        return [
            'column' => 'product_type',
            'map' => ['core' => CoreProduct::class, 'store' => Product::class],
            'order_fields' => ['sortOrder' => Product::class],
        ];
    }

    private static function contentExtension(): array
    {
        return [
            'column' => 'content_type',
            'map' => ['core' => CoreContent::class, 'common' => Content::class],
            'order_fields' => ['sortOrder' => Content::class],
        ];
    }

    private static function emptyMaps(): array
    {
        return ['inheritance' => [], 'ordering' => [], 'controllers' => []];
    }

    private function createModule(string $name, array|string|null $configuration = null): void
    {
        $path = $this->businessRoot.'/'.$name;
        mkdir($path);
        $this->directories[] = $path;
        mkdir($path.'/src');
        $this->directories[] = $path.'/src';

        if ($configuration === null) {
            return;
        }

        mkdir($path.'/config');
        $this->directories[] = $path.'/config';
        $file = $path.'/config/entity_extensions.yaml';
        $this->files[] = $file;
        file_put_contents($file, is_array($configuration) ? Yaml::dump($configuration, 8) : $configuration);
    }

    private function discover(): array
    {
        return EntityExtensionRegistry::discover(ModuleRegistry::discover($this->businessRoot));
    }
}

final class IncompleteExtensionController
{
}

class NonEntityProductExtension extends CoreProduct
{
}

#[ORM\Entity]
class UnmappedOrderProductExtension extends CoreProduct
{
    private int $unmappedOrder = 0;
}
