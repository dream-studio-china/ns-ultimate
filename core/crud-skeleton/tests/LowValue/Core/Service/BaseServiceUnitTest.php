<?php

namespace App\Tests\LowValue\Core\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Service\BaseService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class BaseServiceUnitTest extends TestCase
{
    public function testListResultToCollectionSupportsArrayAndFallback(): void
    {
        $collection = BaseServiceForUnitTest::listResultToCollection([1, 2, 3]);
        self::assertCount(3, $collection);

        $fallback = BaseServiceForUnitTest::listResultToCollection('invalid');
        self::assertCount(0, $fallback);
    }

    public function testGetByIdAndCriteriaAndQueryBuilder(): void
    {
        $entity = new DummyEntity(7, 'name-7');
        $repo = new FakeRepository([7 => $entity], ['title' => $entity]);
        $em = new FakeEntityManager($repo);
        $container = new FakeContainer($em);

        $service = new BaseServiceForUnitTest($container);

        self::assertSame($entity, $service->get(7));
        self::assertSame($entity, $service->get(['title' => $entity]));

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleResult')->willReturn($entity);
        $qb->method('getQuery')->willReturn($query);

        self::assertSame($entity, $service->get($qb));
    }

    public function testExternalExpressionValuesContainExpectedKeys(): void
    {
        $repo = new FakeRepository([], []);
        $em = new FakeEntityManager($repo);
        $container = new FakeContainer($em);
        $service = new BaseServiceForUnitTest($container);

        $values = $service->externalExpressionValues();

        self::assertArrayHasKey('math', $values);
        self::assertArrayHasKey('datetime', $values);
        self::assertArrayHasKey('ArrayCommon', $values);
    }
}

final class BaseServiceForUnitTest extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, DummyEntity::class);
    }
}

final class DummyEntity
{
    public function __construct(private ?int $id = null, private string $title = '') {}
    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
}

final class FakeRepository
{
    /** @param array<int,object> $byId */
    public function __construct(private array $byId, private array $byCriteria) {}

    public function find($id): ?object
    {
        if (is_object($id) && method_exists($id, 'getId')) {
            $id = $id->getId();
        }
        return $this->byId[$id] ?? null;
    }

    public function findOneBy(array $criteria): ?object
    {
        foreach ($criteria as $key => $value) {
            if (($this->byCriteria[$key] ?? null) === $value) {
                return $value;
            }
        }

        return null;
    }
}

final class FakeEntityManager
{
    public function __construct(private readonly FakeRepository $repository) {}
    public function getRepository(string $class): FakeRepository { return $this->repository; }
    public function createQueryBuilder(): object { throw new \LogicException('Not needed in this unit test'); }
    public function persist(object $object): void {}
    public function flush(): void {}
    public function remove(object $object): void {}
}

final class FakeContainer implements ContainerInterface
{
    public function __construct(private readonly FakeEntityManager $entityManager) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->entityManager,
            'logger' => new NullLogger(),
            'security.token_storage' => new class {
                public function getToken(): ?object { return null; }
            },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}
