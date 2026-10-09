<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller\System;

use App\Core\Controller\System\EntityController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EntityControllerExtendedTest extends TestCase
{
    private function s(): SerializerInterface
    {
        return new class implements SerializerInterface {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                return null;
            }
        };
    }

    private function t(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $p = [], ?string $d = null, ?string $l = null): string
            {
                return (string) $id;
            }
            public function getLocale(): string { return 'en'; }
        };
    }

    private function createController(Request $request, EntityManagerInterface $em): EntityController
    {
        $stack = new RequestStack();
        $stack->push($request);

        $controller = new EntityController($em);
        $controller->setRequestStack($stack);
        $controller->setSerializer($this->s());
        $controller->setTranslator($this->t());

        return $controller;
    }

    private function field(string $type, bool $nullable = false, ?int $length = null, ?int $precision = null, ?int $scale = null, bool $unique = false, ?array $options = null): object
    {
        return new class($type, $nullable, $length, $precision, $scale, $unique, $options) {
            public function __construct(
                public readonly string $type,
                public readonly ?bool $nullable = false,
                public readonly ?int $length = null,
                public readonly ?int $precision = null,
                public readonly ?int $scale = null,
                public readonly bool $unique = false,
                public readonly ?array $options = null,
                public readonly string $columnName = '',
            ) {}
        };
    }

    public function testListActionWithSingleEntity(): void
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getName')->willReturn('App\Entity\Product');

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$meta]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['data']);
        self::assertSame('App\Entity\Product', $decoded['data'][0]);
    }

    public function testRetrieveActionWithAllAssociationTypes(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [
            'manyToOne' => ['type' => ClassMetadata::MANY_TO_ONE, 'targetEntity' => 'Target1'],
            'oneToOne' => ['type' => ClassMetadata::ONE_TO_ONE, 'targetEntity' => 'Target2'],
            'manyToMany' => ['type' => ClassMetadata::MANY_TO_MANY, 'targetEntity' => 'Target3'],
            'oneToMany' => ['type' => ClassMetadata::ONE_TO_MANY, 'targetEntity' => 'Target4'],
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with('App\Entity\All')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\All');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ManyToOne', $decoded['data']['manyToOne']['metadata']['type']);
        self::assertSame('OneToOne', $decoded['data']['oneToOne']['metadata']['type']);
        self::assertSame('ManyToMany', $decoded['data']['manyToMany']['metadata']['type']);
        self::assertSame('OneToMany', $decoded['data']['oneToMany']['metadata']['type']);
    }

    public function testRetrieveActionCamelCaseFieldNameSplitting(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [
            'createdDate' => $this->field('datetime'),
            'isActiveNow' => $this->field('boolean'),
        ];
        $classMetadata->associationMappings = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\Foo');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Created date', $decoded['data']['createdDate']['plantext']);
        self::assertSame('Is active now', $decoded['data']['isActiveNow']['plantext']);
    }

    public function testRetrieveActionSingeWordFieldName(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [
            'id' => $this->field('integer'),
            'name' => $this->field('string'),
        ];
        $classMetadata->associationMappings = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\Foo');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Id', $decoded['data']['id']['plantext']);
        self::assertSame('Name', $decoded['data']['name']['plantext']);
    }

    public function testRetrieveActionFieldMappingsWithVariousTypes(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [
            'amount' => $this->field('decimal', precision: 10, scale: 2),
            'description' => $this->field('text', nullable: true),
            'enabled' => $this->field('boolean', options: ['default' => true]),
        ];
        $classMetadata->associationMappings = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\Bar');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('decimal', $decoded['data']['amount']['metadata']['type']);
        self::assertSame(10, $decoded['data']['amount']['metadata']['precision']);
        self::assertSame('text', $decoded['data']['description']['metadata']['type']);
        self::assertTrue($decoded['data']['description']['metadata']['nullable']);
        self::assertSame('boolean', $decoded['data']['enabled']['metadata']['type']);
    }

    public function testSuccessResponseStructureForListAction(): void
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('code', $decoded);
        self::assertArrayHasKey('message', $decoded);
        self::assertSame(0, $decoded['code']);
        self::assertSame('SUCCESS', $decoded['message']);
    }

    public function testSuccessResponseStructureForRetrieveAction(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\X');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('code', $decoded);
        self::assertSame(0, $decoded['code']);
    }

    public function testRetrieveActionReplacesAllSlashesInEntityName(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->with('App\Common\Entity\Nested\Deep')
            ->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App/Common/Entity/Nested/Deep');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRetrieveActionAssociationWithUnknownTypeUsesNumericString(): void
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [
            'unknown' => ['type' => 99, 'targetEntity' => 'Foo'],
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $controller = $this->createController(Request::create('/', 'GET'), $em);
        $response = $controller->retrieveAction('App\Entity\X');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('99', $decoded['data']['unknown']['metadata']['type']);
    }
}
