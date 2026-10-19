<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use NsUltimate\Integration\Backend\BusinessValidationRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;

final class BusinessValidationRegistryTest extends TestCase
{
    private string $modulePath;

    protected function setUp(): void
    {
        $this->modulePath = sys_get_temp_dir().'/ns-business-validation-'.bin2hex(random_bytes(8));
        mkdir($this->modulePath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->modulePath.'/resources/json-schema/*.json') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$this->modulePath.'/config/validation.yaml', $this->modulePath.'/resources/json-schema'] as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        if (is_dir($this->modulePath.'/config')) {
            rmdir($this->modulePath.'/config');
        }
        if (is_dir($this->modulePath.'/resources')) {
            rmdir($this->modulePath.'/resources');
        }
        rmdir($this->modulePath);
    }

    public function testDiscoversMappingsAndNamespacedSchemas(): void
    {
        mkdir($this->modulePath.'/config');
        file_put_contents($this->modulePath.'/config/validation.yaml', "App\\Common\\Entity\\Content: {}\n");
        mkdir($this->modulePath.'/resources');
        mkdir($this->modulePath.'/resources/json-schema');
        $schemaPath = $this->modulePath.'/resources/json-schema/ContentMetadata.json';
        file_put_contents($schemaPath, '{"type":"object"}');

        self::assertSame([
            'mappings' => [$this->modulePath.'/config/validation.yaml'],
            'schemas' => ['Common/ContentMetadata' => $schemaPath],
        ], BusinessValidationRegistry::discover([['name' => 'Common', 'path' => $this->modulePath]]));

        $resources = BusinessValidationRegistry::resources([['name' => 'Common', 'path' => $this->modulePath]]);
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertInstanceOf(FileResource::class, $resources[1]);
        self::assertInstanceOf(FileExistenceResource::class, $resources[2]);
        self::assertInstanceOf(FileResource::class, $resources[4]);
    }

    public function testMissingOptionalFilesAreAcceptedAndTracked(): void
    {
        self::assertSame(['mappings' => [], 'schemas' => []], BusinessValidationRegistry::discover([
            ['name' => 'Common', 'path' => $this->modulePath],
        ]));
        $resources = BusinessValidationRegistry::resources([['name' => 'Common', 'path' => $this->modulePath]]);
        self::assertInstanceOf(FileExistenceResource::class, $resources[0]);
        self::assertInstanceOf(FileExistenceResource::class, $resources[1]);
    }

    public function testInvalidSchemaJsonFailsDuringDiscovery(): void
    {
        mkdir($this->modulePath.'/resources');
        mkdir($this->modulePath.'/resources/json-schema');
        file_put_contents($this->modulePath.'/resources/json-schema/Broken.json', '{invalid');

        $this->expectException(\JsonException::class);
        BusinessValidationRegistry::discover([['name' => 'Common', 'path' => $this->modulePath]]);
    }
}
