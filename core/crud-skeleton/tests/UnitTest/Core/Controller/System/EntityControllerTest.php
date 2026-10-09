<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller\System;

use App\Core\Controller\System\EntityController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class EntityControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private RequestStack $requestStack;
    private SerializerInterface $serializer;
    private TranslatorInterface $translator;
    private EntityController $controller;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = new RequestStack();
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->requestStack->push(Request::create('/system/entities', 'GET'));
        $this->serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $this->translator->method('trans')->willReturnArgument(0);

        $this->controller = new EntityController($this->entityManager);
    }

    private function injectDependencies(): void
    {
        // Simulate Symfony's _instanceof DI calls
        if (method_exists($this->controller, 'setRequestStack')) {
            $this->controller->setRequestStack($this->requestStack);
        }
        if (method_exists($this->controller, 'setSerializer')) {
            $this->controller->setSerializer($this->serializer);
        }
        if (method_exists($this->controller, 'setTranslator')) {
            $this->controller->setTranslator($this->translator);
        }
    }

    private static function field(string $type, bool $nullable = false, ?int $length = null, ?int $precision = null, ?int $scale = null, bool $unique = false, ?array $options = null): object
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

    public function testListActionReturnsAllEntityNames(): void
    {
        $this->injectDependencies();

        $meta1 = $this->createMock(ClassMetadata::class);
        $meta1->method('getName')->willReturn('App\Common\Entity\Category');
        $meta2 = $this->createMock(ClassMetadata::class);
        $meta2->method('getName')->willReturn('App\Common\Entity\Tag');

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$meta1, $meta2]);

        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $response = $this->controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $decoded['code']);
        self::assertSame(['App\Common\Entity\Category', 'App\Common\Entity\Tag'], $decoded['data']);
    }

    #[Group('low-value')]
    public function testListActionReturnsEmptyArrayWhenNoEntities(): void
    {
        $this->injectDependencies();

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);
        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $response = $this->controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['data']);
    }

    public function testRetrieveActionReturnsFieldMappingsAndTranslations(): void
    {
        $this->injectDependencies();

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [
            'name' => self::field('string', nullable: false),
            'age' => self::field('integer', nullable: true),
        ];
        $classMetadata->associationMappings = [];
        $this->entityManager->method('getClassMetadata')->with('App\Entity\User')->willReturn($classMetadata);

        $response = $this->controller->retrieveAction('App\Entity\User');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('name', $decoded['data']);
        self::assertArrayHasKey('age', $decoded['data']);
        self::assertSame('string', $decoded['data']['name']['metadata']['type']);
        self::assertFalse($decoded['data']['name']['metadata']['nullable']);
        self::assertSame('Name', $decoded['data']['name']['plantext']);
        self::assertSame('Name', $decoded['data']['name']['translation']);
        self::assertSame('integer', $decoded['data']['age']['metadata']['type']);
        self::assertTrue($decoded['data']['age']['metadata']['nullable']);
        self::assertSame('Age', $decoded['data']['age']['plantext']);
        self::assertSame('Age', $decoded['data']['age']['translation']);
    }

    #[Group('low-value')]
    public function testRetrieveActionHandlesSlashReplacementInEntityName(): void
    {
        $this->injectDependencies();

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [];
        $this->entityManager->method('getClassMetadata')
            ->with('App\Common\Entity\Category')
            ->willReturn($classMetadata);

        $response = $this->controller->retrieveAction('App/Common/Entity/Category');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $decoded['data']);
    }

    #[Group('low-value')]
    public function testRetrieveActionReturnsAssociationMappings(): void
    {
        $this->injectDependencies();

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->associationMappings = [
            'category' => [
                'type' => ClassMetadata::MANY_TO_ONE,
                'targetEntity' => 'App\Entity\Category',
            ],
            'profile' => [
                'type' => ClassMetadata::ONE_TO_ONE,
                'targetEntity' => 'App\Entity\Profile',
            ],
        ];
        $this->entityManager->method('getClassMetadata')->with('App\Entity\User')->willReturn($classMetadata);

        $response = $this->controller->retrieveAction('App\Entity\User');
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('category', $decoded['data']);
        self::assertSame('ManyToOne', $decoded['data']['category']['metadata']['type']);
        self::assertSame('App\Entity\Category', $decoded['data']['category']['metadata']['targetEntity']);
        self::assertArrayHasKey('profile', $decoded['data']);
        self::assertSame('OneToOne', $decoded['data']['profile']['metadata']['type']);
        self::assertSame('App\Entity\Profile', $decoded['data']['profile']['metadata']['targetEntity']);
    }
}
