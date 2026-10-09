<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\BaseService;
use App\Core\Service\ExpressionServiceInterface;
use App\Core\Service\LegacyEvaluator;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Covers the remaining branches of BaseServiceReadListTrait::list():
 * invalid root aliases, multi-segment join generation, and legacy in-memory
 * filter/sorter failure handling.
 */
#[AllowMockObjectsWithoutExpectations]
final class BaseServiceReadListTraitCoverageTest extends TestCase
{
    private function createService(
        ContainerInterface $container,
        string $entityClass,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator $legacyEvaluator = null,
    ): BaseService {
        return new class($container, $entityClass, $expressionService, $legacyEvaluator) extends BaseService {
            public function __construct(
                ContainerInterface $container,
                string $entityClass,
                ?ExpressionServiceInterface $expressionService,
                ?LegacyEvaluator $legacyEvaluator,
            ) {
                parent::__construct($container, $entityClass, null, $expressionService, $legacyEvaluator);
            }
        };
    }

    public function testListThrowsWhenQueryBuilderHasNoRootAliases(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn([]);

        $em = new ReadListCovEntityManager(new ReadListCovRepository([]));
        $service = $this->createService(new ReadListCovContainer($em), ReadListCovEntity::class);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Invalid query build aliases');
        $service->list($qb, null, true);
    }

    public function testListWithMultiSegmentSelectBuildsJoins(): void
    {
        $repo = new ReadListCovRepository([]);
        $em = new ReadListCovEntityManager($repo);
        $em->setQueryResults([['name' => 'alpha']]);
        $request = new Request(['@select' => 'entity.category.name']);
        $container = new ReadListCovContainer($em, $this->createRequestStack($request));

        $service = $this->createService($container, ReadListCovEntity::class);
        $result = $service->list(null, null, false);

        self::assertSame([['name' => 'alpha']], $result);
        self::assertSame('entity_category.name', $em->lastQueryBuilder?->selectClause);
        self::assertSame(['entity_category' => 'entity.category'], $em->lastQueryBuilder?->joins);
    }

    #[Group('low-value')]
    public function testListLegacyFilterFallbackCatchesEvaluatorFailure(): void
    {
        $alpha = new ReadListCovEntity(1, 'alpha');
        $beta = new ReadListCovEntity(2, 'beta');
        $repo = new ReadListCovRepository([1 => $alpha, 2 => $beta]);
        $em = new ReadListCovEntityManager($repo);
        $em->setQueryResults([$alpha, $beta]);
        $request = new Request(['@filter' => 'entity.getName() == "alpha"']);
        $container = new ReadListCovContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $expressionService = $this->createMock(ExpressionServiceInterface::class);
        $expressionService->method('buildFilter')->willThrowException(new \RuntimeException('unsupported filter'));

        $service = $this->createService(
            $container,
            ReadListCovEntity::class,
            $expressionService,
            new ReadListCovThrowingEvaluator()
        );

        $result = $service->list(null, null, false);

        // Every entity is rejected because the in-memory evaluator fails.
        self::assertSame([], array_values($result));
    }

    #[Group('low-value')]
    public function testListLegacySorterSortsWithWorkingEvaluator(): void
    {
        $alpha = new ReadListCovEntity(1, 'alpha');
        $beta = new ReadListCovEntity(2, 'beta');
        $repo = new ReadListCovRepository([1 => $alpha, 2 => $beta]);
        $em = new ReadListCovEntityManager($repo);
        $em->setQueryResults([$alpha, $beta]);
        $request = new Request(['@sort' => 'x.getId() > y.getId()']);
        $container = new ReadListCovContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $service = $this->createService($container, ReadListCovEntity::class, null, new LegacyEvaluator());
        $result = $service->list(null, null, false);

        // Comparator returns -1 when x <= y, so smaller ids sort first.
        self::assertSame([$alpha, $beta], array_values($result));
    }

    public function testListLegacySorterCatchesEvaluatorFailure(): void
    {
        $alpha = new ReadListCovEntity(1, 'alpha');
        $beta = new ReadListCovEntity(2, 'beta');
        $repo = new ReadListCovRepository([1 => $alpha, 2 => $beta]);
        $em = new ReadListCovEntityManager($repo);
        $em->setQueryResults([$alpha, $beta]);
        $request = new Request(['@sort' => 'x.getId() > y.getId()']);
        $container = new ReadListCovContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $service = $this->createService(
            $container,
            ReadListCovEntity::class,
            null,
            new ReadListCovThrowingEvaluator()
        );

        $result = $service->list(null, null, false);

        // Comparator returns 0 on failure: order preserved, no exception bubbles up.
        self::assertCount(2, $result);
        self::assertSame([$alpha, $beta], array_values($result));
    }

    private function createRequestStack(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function createAdminUser(): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_ADMIN']);

        return $user;
    }
}

final class ReadListCovThrowingEvaluator extends LegacyEvaluator
{
    public function evaluateBool(string $expr, array $context = []): bool
    {
        throw new \RuntimeException('evaluator failure');
    }
}

final class ReadListCovEntity
{
    public function __construct(private ?int $id = null, private string $name = '')
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}

final class ReadListCovRepository
{
    /** @param array<int, object> $byId */
    public function __construct(private readonly array $byId = [])
    {
    }

    public function find($id): ?object
    {
        return $this->byId[$id] ?? null;
    }

    public function findOneBy(array $criteria): ?object
    {
        return null;
    }
}

final class ReadListCovEntityManager
{
    private array $queryResults = [];
    public ?object $lastQueryBuilder = null;

    public function __construct(private readonly ReadListCovRepository $repo)
    {
    }

    public function setQueryResults(array $results): void
    {
        $this->queryResults = $results;
    }

    public function getRepository(string $class): ReadListCovRepository
    {
        return $this->repo;
    }

    public function getClassMetadata(string $class): object
    {
        return new class {
            public function hasField(string $field): bool
            {
                return false;
            }
        };
    }

    public function createQuery(string $dql): object
    {
        return new class ($dql) {
            public function __construct(private readonly string $dql)
            {
            }

            public function getDQL(): string
            {
                return $this->dql;
            }
        };
    }

    public function createQueryBuilder(): object
    {
        $results = $this->queryResults;
        $qb = new class ($results) {
            private array $wheres = [];
            private array $params = [];
            private string $alias = 'entity';
            public $selectClause = null;
            public array $orderBy = [];
            public array $joins = [];
            public ?string $groupByClause = null;

            public function __construct(private array $results)
            {
            }

            public function select($s): self
            {
                $this->selectClause = $s;

                return $this;
            }

            public function from(string $from, string $alias): self
            {
                return $this;
            }

            public function where(mixed $condition): self
            {
                return $this;
            }

            public function andWhere(mixed $condition): self
            {
                $this->wheres[] = $condition;

                return $this;
            }

            public function setParameter(string $name, mixed $value): self
            {
                $this->params[$name] = $value;

                return $this;
            }

            public function addOrderBy(string $field, string $order): self
            {
                $this->orderBy[$field] = $order;

                return $this;
            }

            public function addGroupBy(string $group): self
            {
                $this->groupByClause = $group;

                return $this;
            }

            public function leftJoin(string $join, string $alias): self
            {
                $this->joins[$alias] = $join;

                return $this;
            }

            public function getRootAliases(): array
            {
                return [$this->alias];
            }

            public function getDQL(): string
            {
                return 'SELECT ...';
            }

            public function getQuery(): object
            {
                return new class ($this->results) {
                    public function __construct(private array $results)
                    {
                    }

                    public function setHint(string $k, mixed $v): void
                    {
                    }

                    public function getResult(): array
                    {
                        return $this->results;
                    }
                };
            }
        };
        $this->lastQueryBuilder = $qb;

        return $qb;
    }
}

final class ReadListCovContainer implements ContainerInterface
{
    public function __construct(
        private readonly ReadListCovEntityManager $em,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?UserInterface $user = null,
    ) {
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'request_stack' => $this->requestStack ?? new RequestStack(),
            'security.token_storage' => new class($this->user) {
                public function __construct(private readonly ?UserInterface $user)
                {
                }

                public function getToken(): ?object
                {
                    return $this->user === null ? null : new class($this->user) {
                        public function __construct(private readonly UserInterface $user)
                        {
                        }

                        public function getUser(): UserInterface
                        {
                            return $this->user;
                        }
                    };
                }
            },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        if ($id === 'request_stack') {
            return $this->requestStack !== null;
        }

        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool
    {
        return true;
    }

    public function set(string $id, ?object $service): void
    {
    }

    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
    {
        return null;
    }

    public function hasParameter(string $name): bool
    {
        return false;
    }

    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void
    {
    }
}
