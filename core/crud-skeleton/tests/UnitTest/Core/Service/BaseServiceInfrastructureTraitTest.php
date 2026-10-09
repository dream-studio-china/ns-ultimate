<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\BaseService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;

final class BaseServiceInfrastructureTraitTest extends TestCase
{
    private function createService(ContainerInterface $container, string $entityClass): BaseService
    {
        return new class($container, $entityClass) extends BaseService {
            public function __construct(ContainerInterface $container, string $entityClass)
            {
                parent::__construct($container, $entityClass);
            }
            // Expose protected methods for testing
            public function callGetEntityManager() { return $this->getEntityManager(); }
            public function callGetRepository(?string $class = null) { return $this->getRepository($class); }
            public function callGetLogger() { return $this->getLogger(); }
            public function callGetSerializer() { return $this->getSerializer(); }
            public function callGetValidator() { return $this->getValidator(); }
            public function callGetRequestStack() { return $this->getRequestStack(); }
            public function callGetCurrentRequest() { return $this->getCurrentRequest(); }
            public function callGetQueryBuilderFactory() { return $this->getQueryBuilderFactory(); }
            public function callGetExpressionService() { return $this->getExpressionService(); }
            public function callGetLegacyEvaluator() { return $this->getLegacyEvaluator(); }
        };
    }

    public function testListResultToCollectionWithArray(): void
    {
        $result = BaseService::listResultToCollection([1, 2, 3]);
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(3, $result);
    }

    public function testListResultToCollectionWithInvalid(): void
    {
        $result = BaseService::listResultToCollection(null);
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testListResultToCollectionWithString(): void
    {
        $result = BaseService::listResultToCollection('invalid');
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testExternalExpressionValues(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $values = $service->externalExpressionValues();

        self::assertArrayHasKey('math', $values);
        self::assertArrayHasKey('datetime', $values);
        self::assertArrayHasKey('Math', $values);
        self::assertArrayHasKey('Datetime', $values);
        self::assertArrayHasKey('ArrayCommon', $values);
    }

    public function testGetEntityManagerLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetEntityManager();
        self::assertSame($em, $result);
    }

    public function testGetLoggerLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetLogger();
        self::assertInstanceOf(NullLogger::class, $result);
    }

    public function testGetSerializerCreatesFallback(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em, false); // no serializer
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetSerializer();
        self::assertInstanceOf(\Symfony\Component\Serializer\SerializerInterface::class, $result);
    }

    public function testGetValidatorReturnsNullWhenMissing(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em, true, false); // no validator
        $service = $this->createService($container, InfraDummyEntity::class);

        self::assertNull($service->callGetValidator());
    }

    public function testGetQueryBuilderFactoryLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetQueryBuilderFactory();
        self::assertNotNull($result);
    }

    public function testGetExpressionServiceLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetExpressionService();
        self::assertNotNull($result);
    }

    public function testGetLegacyEvaluatorLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetLegacyEvaluator();
        self::assertNotNull($result);
    }

    public function testGetCurrentRequestWithoutRequestStack(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeNoRequestStackContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        self::assertNull($service->callGetCurrentRequest());
    }

    public function testGetCurrentRequestWithActiveRequest(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);
        $container = new InfraFakeContainer($em, true, true, $stack);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetCurrentRequest();
        self::assertSame($request, $result);
    }
}

final class InfraDummyEntity
{
    public function __construct(private ?int $id = null) {}
    public function getId(): ?int { return $this->id; }
}

final class InfraFakeRepository
{
    public function find($id): ?object { return null; }
    public function findOneBy(array $criteria): ?object { return null; }
}

final class InfraFakeEntityManager
{
    public function __construct(private readonly InfraFakeRepository $repo) {}
    public function getRepository(string $class): InfraFakeRepository { return $this->repo; }
    public function createQueryBuilder(): object { throw new \LogicException('not needed'); }
}

final class InfraFakeContainer implements ContainerInterface
{
    public function __construct(
        private readonly InfraFakeEntityManager $em,
        private readonly bool $hasSerializer = true,
        private readonly bool $hasValidator = true,
        private ?RequestStack $requestStack = null,
    ) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'request_stack' => $this->requestStack,
            'security.token_storage' => new class { public function getToken(): ?object { return null; } },
            'serializer' => $this->hasSerializer ? new \Symfony\Component\Serializer\Serializer([new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]) : null,
            'validator' => $this->hasValidator ? new class { public function validate(object $obj) { return []; } } : null,
            default => null,
        };
    }

    public function has(string $id): bool
    {
        if ($id === 'request_stack') return $this->requestStack !== null;
        if ($id === 'serializer') return $this->hasSerializer;
        if ($id === 'validator') return $this->hasValidator;
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}

final class InfraFakeNoRequestStackContainer implements ContainerInterface
{
    public function __construct(private readonly InfraFakeEntityManager $em) {}

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
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}
