<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\BaseService;
use App\Core\Service\ServiceLocatorInterface;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers the remaining branches of BaseServiceInfrastructureTrait:
 * container-backed lazy EM/logger/serializer accessors, repository resolution,
 * and both wrapInTransaction() code paths.
 */
#[AllowMockObjectsWithoutExpectations]
final class BaseServiceInfrastructureTraitCoverageTest extends TestCase
{
    private function createService(
        ContainerInterface $container,
        string $entityClass,
        ?ServiceLocatorInterface $locator = null,
        bool $nullEm = false,
        bool $nullLogger = false,
    ): BaseService {
        return new class($container, $entityClass, $locator, $nullEm, $nullLogger) extends BaseService {
            public function __construct(
                ContainerInterface $container,
                string $entityClass,
                ?ServiceLocatorInterface $locator,
                bool $nullEm,
                bool $nullLogger,
            ) {
                parent::__construct($container, $entityClass, $locator);
                if ($nullEm) {
                    $this->em = null;
                    $this->rep = null;
                }
                if ($nullLogger) {
                    $this->logger = null;
                }
            }

            public function callGetEntityManager()
            {
                return $this->getEntityManager();
            }

            public function callGetRepository(?string $class = null)
            {
                return $this->getRepository($class);
            }

            public function callGetLogger()
            {
                return $this->getLogger();
            }

            public function callGetSerializer()
            {
                return $this->getSerializer();
            }

            public function callWrapInTransaction(callable $fn)
            {
                return $this->wrapInTransaction($fn);
            }
        };
    }

    public function testListResultToCollectionWithQueryBuilderReturnsResult(): void
    {
        $entities = [new InfraCovEntity(1), new InfraCovEntity(2)];
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($entities);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $collection = BaseService::listResultToCollection($qb);
        self::assertInstanceOf(ArrayCollection::class, $collection);
        self::assertCount(2, $collection);
    }

    public function testListResultToCollectionWithQueryBuilderEmptyResult(): void
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $collection = BaseService::listResultToCollection($qb);
        self::assertInstanceOf(ArrayCollection::class, $collection);
        self::assertCount(0, $collection);
    }

    public function testGetEntityManagerFetchesFromContainerLazily(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class, null, nullEm: true);

        self::assertSame($em, $service->callGetEntityManager());
        self::assertSame($em, $service->callGetEntityManager());
    }

    public function testGetEntityManagerThrowsWhenUnavailable(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovNoEmContainer();
        $locator = new InfraCovLocator(em: $em);
        $service = $this->createService($container, InfraCovEntity::class, $locator, nullEm: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EntityManager not available');
        $service->callGetEntityManager();
    }

    public function testGetRepositoryUsesCachedRepositoryForDefaultClass(): void
    {
        $repo = new InfraCovRepository([]);
        $em = new InfraCovEntityManager($repo);
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        self::assertSame($repo, $service->callGetRepository());
    }

    public function testGetRepositoryResolvesAlternateClassThroughEm(): void
    {
        $repo = new InfraCovRepository([]);
        $em = new InfraCovEntityManager($repo);
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        self::assertSame($repo, $service->callGetRepository('App\\Other\\Entity'));
    }

    public function testGetLoggerFetchesFromContainer(): void
    {
        $logger = new NullLogger();
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em, $logger);
        $service = $this->createService($container, InfraCovEntity::class, null, nullLogger: true);

        self::assertSame($logger, $service->callGetLogger());
    }

    public function testGetLoggerCatchesContainerExceptionAndReturnsNullLogger(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovThrowingLoggerContainer($em);
        $locator = new InfraCovLocator(em: $em);
        $service = $this->createService($container, InfraCovEntity::class, $locator, nullLogger: true);

        self::assertInstanceOf(NullLogger::class, $service->callGetLogger());
    }

    public function testGetLoggerReturnsNullLoggerWhenLoggerServiceMissing(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovNoLoggerContainer($em);
        $locator = new InfraCovLocator(em: $em);
        $service = $this->createService($container, InfraCovEntity::class, $locator, nullLogger: true);

        self::assertInstanceOf(NullLogger::class, $service->callGetLogger());
    }

    public function testGetSerializerCachesFromServiceLocator(): void
    {
        $serializer = new \Symfony\Component\Serializer\Serializer([], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]);
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em);
        $locator = new InfraCovLocator(em: $em, serializer: $serializer);
        $service = $this->createService($container, InfraCovEntity::class, $locator);

        self::assertSame($serializer, $service->callGetSerializer());
        // Second call must hit the cached branch.
        self::assertSame($serializer, $service->callGetSerializer());
    }

    public function testGetSerializerCatchesLocatorExceptionAndUsesContainer(): void
    {
        $serializer = new \Symfony\Component\Serializer\Serializer([], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]);
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em, serializer: $serializer);
        $locator = new InfraCovThrowingSerializerLocator($em);
        $service = $this->createService($container, InfraCovEntity::class, $locator);

        self::assertSame($serializer, $service->callGetSerializer());
    }

    public function testGetSerializerCatchesContainerExceptionAndFallsBack(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovThrowingSerializerContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $serializer = $service->callGetSerializer();
        self::assertInstanceOf(\Symfony\Component\Serializer\SerializerInterface::class, $serializer);
    }

    public function testWrapInTransactionFallsBackWithoutTransactionSupport(): void
    {
        $em = new InfraCovEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $result = $service->callWrapInTransaction(function ($em) {
            return 'called';
        });

        self::assertSame('called', $result);
        self::assertSame(1, $em->flushCount);
    }

    public function testWrapInTransactionFallsBackWithoutFlushMethod(): void
    {
        $em = new InfraCovNoFlushEntityManager(new InfraCovRepository([]));
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $result = $service->callWrapInTransaction(fn ($em) => 'fallback');

        self::assertSame('fallback', $result);
    }

    public function testWrapInTransactionCommits(): void
    {
        $em = new InfraCovTransactionalEntityManager(new InfraCovRepository([]), true);
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $result = $service->callWrapInTransaction(function ($em) {
            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertTrue($em->begun);
        self::assertTrue($em->flushed);
        self::assertTrue($em->committed);
        self::assertFalse($em->rolledBack);
    }

    public function testWrapInTransactionRollsBackOnThrowable(): void
    {
        $em = new InfraCovTransactionalEntityManager(new InfraCovRepository([]), true);
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $service->callWrapInTransaction(function () {
            throw new \RuntimeException('boom');
        });
    }

    public function testWrapInTransactionSkipsRollbackWhenTransactionInactive(): void
    {
        $em = new InfraCovTransactionalEntityManager(new InfraCovRepository([]), false);
        $container = new InfraCovContainer($em);
        $service = $this->createService($container, InfraCovEntity::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $service->callWrapInTransaction(function () {
            throw new \RuntimeException('boom');
        });

        self::assertFalse($em->rolledBack);
    }

    public function testWrapInTransactionRecoversClosedEntityManager(): void
    {
        $closedEm = new InfraCovTransactionalEntityManager(new InfraCovRepository([]), true, false);
        $freshEm = $this->createStub(\Doctrine\Persistence\ObjectManager::class);

        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManager')->willReturn($freshEm);
        $registry->expects(self::once())->method('resetManager');

        $container = new InfraCovContainer($closedEm, null, null, $registry);
        $service = $this->createService($container, InfraCovEntity::class);

        try {
            $service->callWrapInTransaction(function () {
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // After automatic EM recovery, the service uses the fresh EntityManager.
        self::assertSame($freshEm, $service->callGetEntityManager());
        self::assertTrue($closedEm->rolledBack);
    }
}

final class InfraCovEntity
{
    public function __construct(private ?int $id = null)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

final class InfraCovRepository
{
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

final class InfraCovEntityManager
{
    public int $flushCount = 0;

    public function __construct(private readonly InfraCovRepository $repo)
    {
    }

    public function getRepository(string $class): InfraCovRepository
    {
        return $this->repo;
    }

    public function createQueryBuilder(): object
    {
        throw new \LogicException('not needed');
    }

    public function flush(): void
    {
        $this->flushCount++;
    }
}

final class InfraCovNoFlushEntityManager
{
    public function __construct(private readonly InfraCovRepository $repo)
    {
    }

    public function getRepository(string $class): InfraCovRepository
    {
        return $this->repo;
    }

    public function createQueryBuilder(): object
    {
        throw new \LogicException('not needed');
    }
}

final class InfraCovTransactionalEntityManager
{
    public bool $begun = false;
    public bool $flushed = false;
    public bool $committed = false;
    public bool $rolledBack = false;

    public function __construct(
        private readonly InfraCovRepository $repo,
        private readonly bool $transactionActive,
        private readonly bool $open = true,
    ) {
    }

    public function getRepository(string $class): InfraCovRepository
    {
        return $this->repo;
    }

    public function createQueryBuilder(): object
    {
        throw new \LogicException('not needed');
    }

    public function beginTransaction(): void
    {
        $this->begun = true;
    }

    public function flush(): void
    {
        $this->flushed = true;
    }

    public function commit(): void
    {
        $this->committed = true;
    }

    public function rollback(): void
    {
        $this->rolledBack = true;
    }

    public function getConnection(): object
    {
        return new class($this->transactionActive) {
            public function __construct(private readonly bool $active)
            {
            }

            public function isTransactionActive(): bool
            {
                return $this->active;
            }
        };
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}

final class InfraCovContainer implements ContainerInterface
{
    public function __construct(
        private readonly object $em,
        private readonly ?object $logger = null,
        private readonly ?object $serializer = null,
        private readonly ?object $registry = null,
    ) {
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'doctrine' => $this->registry,
            'logger' => $this->logger ?? new NullLogger(),
            'serializer' => $this->serializer,
            'security.token_storage' => new class {
                public function getToken(): ?object
                {
                    return null;
                }
            },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        if ($id === 'serializer') {
            return $this->serializer !== null;
        }
        if ($id === 'doctrine') {
            return $this->registry !== null;
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

final class InfraCovNoEmContainer implements ContainerInterface
{
    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return null;
    }

    public function has(string $id): bool
    {
        return false;
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

final class InfraCovThrowingLoggerContainer implements ContainerInterface
{
    public function __construct(private readonly InfraCovEntityManager $em)
    {
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return $this->em;
    }

    public function has(string $id): bool
    {
        if ($id === 'logger') {
            throw new \RuntimeException('container lookup failed');
        }

        return in_array($id, ['doctrine.orm.entity_manager', 'security.token_storage'], true);
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

final class InfraCovNoLoggerContainer implements ContainerInterface
{
    public function __construct(private readonly InfraCovEntityManager $em)
    {
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if ($id === 'doctrine.orm.entity_manager') {
            return $this->em;
        }

        return null;
    }

    public function has(string $id): bool
    {
        return $id === 'doctrine.orm.entity_manager';
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

final class InfraCovThrowingSerializerContainer implements ContainerInterface
{
    public function __construct(private readonly InfraCovEntityManager $em)
    {
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if ($id === 'doctrine.orm.entity_manager') {
            return $this->em;
        }

        if ($id === 'serializer' || $id === \Symfony\Component\Serializer\SerializerInterface::class) {
            throw new \RuntimeException('serializer lookup failed');
        }

        return null;
    }

    public function has(string $id): bool
    {
        if ($id === 'serializer') {
            throw new \RuntimeException('serializer lookup failed');
        }

        return $id === 'doctrine.orm.entity_manager';
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

final class InfraCovLocator implements ServiceLocatorInterface
{
    public function __construct(
        private readonly mixed $em = null,
        private readonly mixed $serializer = null,
    ) {
    }

    public function getEntityManager()
    {
        return $this->em;
    }

    public function getLogger()
    {
        return new NullLogger();
    }

    public function getTokenStorage()
    {
        return null;
    }

    public function getRequestStack(): ?RequestStack
    {
        return null;
    }

    public function getSerializer()
    {
        return $this->serializer;
    }

    public function getValidator()
    {
        return null;
    }
}

final class InfraCovThrowingSerializerLocator implements ServiceLocatorInterface
{
    public function __construct(private readonly mixed $em)
    {
    }

    public function getEntityManager()
    {
        return $this->em;
    }

    public function getLogger()
    {
        return new NullLogger();
    }

    public function getTokenStorage()
    {
        return null;
    }

    public function getRequestStack(): ?RequestStack
    {
        return null;
    }

    public function getSerializer()
    {
        throw new \RuntimeException('serializer unavailable');
    }

    public function getValidator()
    {
        return null;
    }
}
