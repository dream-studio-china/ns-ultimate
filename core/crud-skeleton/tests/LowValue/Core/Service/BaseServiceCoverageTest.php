<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Core\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Service\BaseService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Group('low-value')]
final class BaseServiceCoverageTest extends TestCase
{
    public function testListWithNoFilter(): void
    {
        $e1 = new CoverageEntity(1, 'alpha');
        $e2 = new CoverageEntity(2, 'beta');
        $repo = new CoverageFakeRepository([1 => $e1, 2 => $e2]);
        $em = new CoverageFakeEntityManager($repo);
        $em->setQueryResults([$e1, $e2]);
        $container = new CoverageFakeContainer($em);

        $service = new CoverageBaseService($container, CoverageEntity::class);
        $result = $service->list(null, null, true);

        self::assertIsArray($result);
        self::assertCount(2, $result);
    }

    public function testListWithArrayFilter(): void
    {
        $e1 = new CoverageEntity(1, 'alpha');
        $e2 = new CoverageEntity(2, 'beta');
        $repo = new CoverageFakeRepository([1 => $e1, 2 => $e2]);
        $em = new CoverageFakeEntityManager($repo);
        $em->setQueryResults([$e1]);
        $container = new CoverageFakeContainer($em);

        $service = new CoverageBaseService($container, CoverageEntity::class);
        $result = $service->list(['name' => 'alpha'], null, true);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('alpha', $result[0]->getName());
    }

    public function testGetByIntegerId(): void
    {
        $entity = new CoverageEntity(7, 'test-entity');
        $repo = new CoverageFakeRepository([7 => $entity]);
        $em = new CoverageFakeEntityManager($repo);
        $container = new CoverageFakeContainer($em);

        $service = new CoverageBaseService($container, CoverageEntity::class);
        $result = $service->get(7);

        self::assertSame($entity, $result);
    }

    public function testGetByArray(): void
    {
        $entity = new CoverageEntity(3, 'match');
        $repo = new CoverageFakeRepository([3 => $entity]);
        $em = new CoverageFakeEntityManager($repo);
        $container = new CoverageFakeContainer($em);

        $service = new CoverageBaseService($container, CoverageEntity::class);
        $result = $service->get(['name' => 'match']);

        self::assertSame($entity, $result);
    }

    public function testNewOnStdClass(): void
    {
        $repo = new CoverageFakeRepository([]);
        $em = new CoverageFakeEntityManager($repo);
        $container = new CoverageFakeContainer($em);

        $service = new CoverageBaseService($container, \stdClass::class);
        $entity = $service->new();

        self::assertInstanceOf(\stdClass::class, $entity);
    }
}

final class CoverageBaseService extends BaseService
{
    public function __construct(ContainerInterface $container, string $entityClass)
    {
        parent::__construct($container, $entityClass);
    }
}

final class CoverageEntity
{
    public function __construct(private ?int $id = null, private string $name = '') {}
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
}

final class CoverageFakeRepository
{
    public function __construct(private array $byId) {}

    public function find($id): ?object
    {
        if (is_object($id) && method_exists($id, 'getId')) {
            $id = $id->getId();
        }
        return $this->byId[$id] ?? null;
    }

    public function findOneBy(array $criteria): ?object
    {
        foreach ($this->byId as $entity) {
            $match = true;
            foreach ($criteria as $k => $v) {
                $getter = 'get' . ucfirst($k);
                if (!method_exists($entity, $getter) || $entity->$getter() !== $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $entity;
            }
        }
        return null;
    }
}

final class CoverageFakeEntityManager
{
    private array $queryResults = [];

    public function __construct(private readonly CoverageFakeRepository $repo) {}
    public function getRepository(string $class): CoverageFakeRepository { return $this->repo; }
    public function setQueryResults(array $results): void { $this->queryResults = $results; }

    public function createQuery(string $dql): object
    {
        return new class { public function getDQL(): string { return ''; } };
    }

    public function createQueryBuilder(): object
    {
        $results = $this->queryResults;
        return new class ($results) {
            private array $wheres = [];
            private array $params = [];
            private string $alias = 'entity';
            private $selectClause = null;
            private array $orderBy = [];
            private array $joins = [];
            private $groupByClause = null;

            public function __construct(private array $results) {}
            public function select($s): self { $this->selectClause = $s; return $this; }
            public function from(string $from, string $alias): self { return $this; }
            public function where(string $condition): self { return $this; }
            public function andWhere(string $condition): self { $this->wheres[] = $condition; return $this; }
            public function setParameter(string $name, mixed $value): self { $this->params[$name] = $value; return $this; }
            public function addOrderBy(string $field, string $order): self { $this->orderBy[$field] = $order; return $this; }
            public function addGroupBy(string $group): self { $this->groupByClause = $group; return $this; }
            public function leftJoin(string $join, string $alias): self { $this->joins[$alias] = $join; return $this; }
            public function getRootAliases(): array { return [$this->alias]; }
            public function getDQL(): string { return 'SELECT ...'; }
            public function getQuery(): object
            {
                return new class ($this->results) {
                    public function __construct(private array $results) {}
                    public function setHint(string $k, mixed $v): void {}
                    public function getResult(): array { return $this->results; }
                    public function getSingleResult(): mixed { return $this->results[0] ?? null; }
                };
            }
        };
    }
}

final class CoverageFakeContainer implements ContainerInterface
{
    public function __construct(private readonly CoverageFakeEntityManager $em) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'security.token_storage' => new class { public function getToken(): ?object { return null; } },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, mixed $value): void {}
}
