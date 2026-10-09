<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Exception\ValidatorException;

final class TransformContentTest extends TestCase
{
    public function testServiceGatewayAllowsGetAndList(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);
        $entity = new TransformInputEntity();

        $getResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get({'name': ':value'}).getId()"],
            $entity,
        );
        $listResult = $controller->transform(
            ['relation' => 'all'],
            ['relation' => "Service.list(':value')[0].getId()"],
            $entity,
        );

        self::assertSame(11, $getResult['relation']);
        self::assertSame(['name' => 'lookup'], $service->getCriteria);
        self::assertSame(22, $listResult['relation']);
        self::assertSame('all', $service->listCriteria);
    }

    public function testServiceGatewayDoesNotExposeOtherServiceMethods(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'Service.eraseEverything()'],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $result['relation']);
        self::assertFalse($service->wasErased);
    }

    public function testServiceGatewayDoesNotExposeRetrievedEntityMethods(): void
    {
        $controller = $this->createController(new TransformLookupService());

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get(':value').getSecret()"],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $result['relation']);
    }

    public function testEntityGatewayBlocksMutatorsAndRelationGetters(): void
    {
        $entity = new TransformInputEntity();
        $controller = $this->createController(new TransformLookupService());

        $mutatorResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "entity.setSensitive(':value')"],
            $entity,
        );
        $relationResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'entity.getUser().getEmail()'],
            $entity,
        );

        self::assertSame('lookup', $mutatorResult['relation']);
        self::assertSame('lookup', $relationResult['relation']);
        self::assertFalse($entity->wasMutated);
        self::assertFalse($entity->userWasRead);
    }

    public function testServiceGatewayRejectsObjectCriteria(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'Service.get(entity).getId()'],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $result['relation']);
        self::assertNull($service->getCriteria);
    }

    public function testServiceGatewayRejectsUnboundedListQueries(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'Service.list()[0].getId()'],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $result['relation']);
        self::assertNull($service->listCriteria);
    }

    public function testServiceGatewayRejectsNestedCriteriaAndInvalidListResults(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);

        $nestedCriteriaResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get({'name': {'nested': 'value'}}).getId()"],
            new TransformInputEntity(),
        );

        $service->useListResult = true;
        $service->listResult = new TransformLookupResult(22);
        $nonIterableResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.list(':value')[0].getId()"],
            new TransformInputEntity(),
        );

        $service->listResult = ['not-an-entity'];
        $nonObjectResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.list(':value')[0].getId()"],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $nestedCriteriaResult['relation']);
        self::assertNull($service->getCriteria);
        self::assertSame('lookup', $nonIterableResult['relation']);
        self::assertSame('lookup', $nonObjectResult['relation']);
    }

    public function testServiceGatewayHandlesMissingServicesAndNullResults(): void
    {
        $controller = $this->createController(new class {});
        $entity = new TransformInputEntity();

        $missingMethodResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get(':value').getId()"],
            $entity,
        );
        $missingListMethodResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.list(':value')[0].getId()"],
            $entity,
        );
        $missingServiceResult = $controller->transform(
            ['plain' => 'value'],
            ['plain' => "Service.get(':value').getId()"],
            $entity,
        );

        $service = new TransformLookupService();
        $service->returnNullFromGet = true;
        $nullResult = $this->createController($service)->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get(':value').getId()"],
            $entity,
        );

        self::assertSame('lookup', $missingMethodResult['relation']);
        self::assertSame('lookup', $missingListMethodResult['relation']);
        self::assertSame('value', $missingServiceResult['plain']);
        self::assertNull($nullResult['relation']);
    }

    public function testEntityGatewayHandlesEntitiesWithoutAnIdGetter(): void
    {
        $controller = $this->createController(new TransformLookupService());

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'entity.getId()'],
            new TransformInputEntityWithoutId(),
        );

        self::assertNull($result['relation']);
    }

    public function testEntityGatewayExposesItsId(): void
    {
        $result = $this->createController(new TransformLookupService())->transform(
            ['relation' => 'lookup'],
            ['relation' => 'entity.getId()'],
            new TransformInputEntity(),
        );

        self::assertSame(7, $result['relation']);
    }

    public function testTransformSkipsMissingFieldsAndRejectsUnknownFields(): void
    {
        $controller = $this->createController(new TransformLookupService());

        self::assertSame([], $controller->transform(
            [],
            ['relation' => "Service.get(':value').getId()"],
            new TransformInputEntity(),
        ));

        $this->expectException(ValidatorException::class);
        $controller->transform(
            ['unknown' => 'value'],
            ['unknown' => "Service.get(':value').getId()"],
            new TransformInputEntity(),
        );
    }

    public function testServiceGatewaySupportsAllAssociationMappingTypes(): void
    {
        $controller = $this->createController(new TransformLookupService());

        foreach ([
            new TransformOneToOneInputEntity(),
            new TransformManyToManyInputEntity(),
            new TransformOneToManyInputEntity(),
        ] as $entity) {
            $result = $controller->transform(
                ['relation' => 'lookup'],
                ['relation' => "Service.get(':value').getId()"],
                $entity,
            );

            self::assertSame(11, $result['relation']);
        }
    }

    private function createController(object $service): object
    {
        $controller = new class extends RestController {
            use ApiView;

            public function transform(array $content, array $transformer, object $entity): array
            {
                return $this->transformContent($content, $transformer, $entity);
            }
        };

        $container = new Container();
        $container->set(
            str_replace('Entity', 'Service', TransformLookupEntity::class) . 'Service',
            $service,
        );
        $controller->setServiceContainer($container);

        return $controller;
    }
}

final class TransformInputEntity
{
    #[ORM\ManyToOne(targetEntity: TransformLookupEntity::class)]
    private ?object $relation = null;
    private string $plain = '';
    public bool $wasMutated = false;
    public bool $userWasRead = false;

    public function getId(): int
    {
        return 7;
    }

    public function setSensitive(string $value): void
    {
        $this->wasMutated = true;
    }

    public function getUser(): object
    {
        $this->userWasRead = true;

        return new class {
            public function getEmail(): string
            {
                return 'private@example.test';
            }
        };
    }
}

final class TransformInputEntityWithoutId
{
    #[ORM\ManyToOne(targetEntity: TransformLookupEntity::class)]
    private ?object $relation = null;
}

final class TransformOneToOneInputEntity
{
    #[ORM\OneToOne(targetEntity: TransformLookupEntity::class)]
    private ?object $relation = null;
}

final class TransformManyToManyInputEntity
{
    #[ORM\ManyToMany(targetEntity: TransformLookupEntity::class)]
    private ?object $relation = null;
}

final class TransformOneToManyInputEntity
{
    #[ORM\OneToMany(targetEntity: TransformLookupEntity::class, mappedBy: 'relation')]
    private ?object $relation = null;
}

final class TransformLookupEntity
{
}

final class TransformLookupService
{
    public mixed $getCriteria = null;
    public mixed $listCriteria = null;
    public bool $wasErased = false;
    public bool $returnNullFromGet = false;
    public bool $useListResult = false;
    public mixed $listResult = null;

    public function get(mixed $criteria): ?TransformLookupResult
    {
        $this->getCriteria = $criteria;

        return $this->returnNullFromGet ? null : new TransformLookupResult(11);
    }

    /** @return list<TransformLookupResult> */
    public function list(mixed $criteria = null): mixed
    {
        $this->listCriteria = $criteria;

        return $this->useListResult ? $this->listResult : [new TransformLookupResult(22)];
    }

    public function eraseEverything(): void
    {
        $this->wasErased = true;
    }
}

final class TransformLookupResult
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSecret(): string
    {
        return 'secret';
    }
}
