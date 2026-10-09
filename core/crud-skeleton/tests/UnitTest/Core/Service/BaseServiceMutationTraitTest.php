<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\BaseService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Doctrine\ORM\Mapping as ORM;

#[AllowMockObjectsWithoutExpectations]
final class BaseServiceMutationTraitTest extends TestCase
{
    private function createService(ContainerInterface $container, string $entityClass): BaseService
    {
        return new class($container, $entityClass) extends BaseService {
            public function __construct(ContainerInterface $container, string $entityClass)
            {
                parent::__construct($container, $entityClass);
            }
        };
    }

    // -------------------------------------------------------
    //  new()
    // -------------------------------------------------------

    public function testNewWithNoConstructor(): void
    {
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, SimpleEntity::class);

        $entity = $service->new();
        self::assertInstanceOf(SimpleEntity::class, $entity);
    }

    public function testNewWithRequiredConstructor(): void
    {
        $repo = new MutationFakeRepository([]);
        $em = new MutationFakeEntityManager($repo);
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, EntityWithRequiredCtor::class);

        $entity = $service->new();
        self::assertInstanceOf(EntityWithRequiredCtor::class, $entity);
    }

    // -------------------------------------------------------
    //  update() with basic data
    // -------------------------------------------------------

    public function testUpdateWithScalarData(): void
    {
        $entity = new SimpleEntity();
        $entity->setName('old');

        $repo = new MutationFakeRepository([1 => $entity]);
        $em = new MutationFakeEntityManager($repo);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(function ($data, $type, $format, $ctx) {
            $obj = $ctx['object_to_populate'];
            $decoded = json_decode($data, true);
            foreach ($decoded as $k => $v) {
                $setter = 'set' . ucfirst($k);
                if (method_exists($obj, $setter)) {
                    $obj->$setter($v);
                }
            }
        });
        $container = new MutationFakeContainer($em, $serializer);
        $service = $this->createService($container, SimpleEntity::class);

        $result = $service->update($entity, ['name' => 'new name']);

        self::assertInstanceOf(SimpleEntity::class, $result);
        self::assertSame('new name', $result->getName());
    }

    public function testUpdateWithExistingIdRefetches(): void
    {
        $entity = new SimpleEntity();
        $entity->setId(5);
        $entity->setName('stale');

        $fresh = new SimpleEntity();
        $fresh->setId(5);
        $fresh->setName('fresh');

        $repo = new MutationFakeRepository([5 => $fresh]);
        $em = new MutationFakeEntityManager($repo);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(function ($data, $type, $format, $ctx) {
            $obj = $ctx['object_to_populate'];
            $decoded = json_decode($data, true);
            foreach ($decoded as $k => $v) {
                $setter = 'set' . ucfirst($k);
                if (method_exists($obj, $setter)) {
                    $obj->$setter($v);
                }
            }
        });
        $container = new MutationFakeContainer($em, $serializer);
        $service = $this->createService($container, SimpleEntity::class);

        $result = $service->update($entity, ['name' => 'updated']);
        // Should use the fresh entity from repo, not the stale one
        self::assertSame('updated', $result->getName());
    }

    public function testUpdateWithNullObjectThrows(): void
    {
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, SimpleEntity::class);

        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $service->update(null, ['name' => 'test']);
    }

    public function testUpdateWithNonObjectThrows(): void
    {
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $service = $this->createService(new MutationFakeContainer($em), SimpleEntity::class);

        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $service->update(42, ['name' => 'test']);
    }

    public function testUpdateCanSkipFlush(): void
    {
        $entity = new SimpleEntity();
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $service = $this->createService(new MutationFakeContainer($em), SimpleEntity::class);

        self::assertSame($entity, $service->update($entity, null, true));
        self::assertSame([$entity], $em->persisted());
        self::assertSame(0, $em->flushCount());
    }

    public function testUpdateRejectsValidationErrors(): void
    {
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList([
            $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class),
        ]));
        $service = $this->createService(new MutationFakeContainer($em, null, $validator), SimpleEntity::class);

        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $service->update(new SimpleEntity());
    }

    public function testUpdateResolvesToOneRelationship(): void
    {
        $owner = new MutationRelatedEntity(7);
        $entity = new MutationRelationEntity();
        $em = new MutationFakeEntityManager(new MutationFakeRepository([7 => $owner]));
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('deserialize')->with('[]', MutationRelationEntity::class, 'json', self::isArray());
        $service = $this->createService(new MutationFakeContainer($em, $serializer), MutationRelationEntity::class);

        self::assertSame($entity, $service->update($entity, ['owner' => 7]));
        self::assertSame($owner, $entity->getOwner());
    }

    public function testUpdateSynchronizesToManyRelationship(): void
    {
        $old = new MutationRelatedEntity(1);
        $kept = new MutationRelatedEntity(2);
        $added = new MutationRelatedEntity(3);
        $entity = new MutationCollectionEntity([$old, $kept]);
        $em = new MutationFakeEntityManager(new MutationFakeRepository([1 => $old, 2 => $kept, 3 => $added]));
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('deserialize')->with('[]', MutationCollectionEntity::class, 'json', self::isArray());
        $service = $this->createService(new MutationFakeContainer($em, $serializer), MutationCollectionEntity::class);

        self::assertSame($entity, $service->update($entity, ['members' => [2, 3]]));
        self::assertSame([$kept, $added], array_values($entity->getMembers()->toArray()));
    }

    public function testUpdateConvertsDateMapping(): void
    {
        $entity = new MutationDateEntity();
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('deserialize')->with('[]', MutationDateEntity::class, 'json', self::isArray());
        $service = $this->createService(new MutationFakeContainer($em, $serializer), MutationDateEntity::class);

        $service->update($entity, ['publishedAt' => '2026-07-25 12:00:00']);

        self::assertInstanceOf(\DateTime::class, $entity->getPublishedAt());
        self::assertSame('2026-07-25 12:00:00', $entity->getPublishedAt()?->format('Y-m-d H:i:s'));
    }

    // -------------------------------------------------------
    //  remove()
    // -------------------------------------------------------

    public function testRemoveReturnsTrue(): void
    {
        $entity = new SimpleEntity();
        $entity->setId(1);

        $repo = new MutationFakeRepository([1 => $entity]);
        $em = new MutationFakeEntityManager($repo);
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, SimpleEntity::class);

        self::assertTrue($service->remove(1));
    }

    public function testRemoveReturnsFalseOnFailure(): void
    {
        $entity = new SimpleEntity();
        $entity->setId(1);

        $repo = new MutationFakeRepository([1 => $entity]);
        $em = new MutationFakeEntityManager($repo, true); // throws on flush
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, SimpleEntity::class);

        self::assertFalse($service->remove(1));
    }

    public function testRemoveReturnsFalseWhenObjectCannotBeFound(): void
    {
        $em = new MutationFakeEntityManager(new MutationFakeRepository([]));
        $service = $this->createService(new MutationFakeContainer($em), SimpleEntity::class);

        self::assertFalse($service->remove(999));
    }

    public function testUpdateWithoutSerializerThrowsForRichData(): void
    {
        $entity = new SimpleEntity();
        $entity->setId(5);
        $entity->setName('old');

        $repo = new MutationFakeRepository([5 => $entity]);
        $em = new MutationFakeEntityManager($repo);
        $container = new MutationFakeContainer($em);
        $service = $this->createService($container, SimpleEntity::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Serializer service is not available');
        $service->update($entity, ['name' => ['new' => 'value']]);
    }
}

// -------------------------------------------------------
//  Fake dependencies for MutationTrait tests
// -------------------------------------------------------

final class SimpleEntity
{
    private ?int $id = null;
    private string $name = '';
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }
}

final class EntityWithRequiredCtor
{
    public function __construct(private string $name) {}
    public function getId(): ?int { return null; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
}

final class MutationRelatedEntity
{
    public function __construct(private readonly int $id) {}
    public function getId(): int { return $this->id; }
}

final class MutationRelationEntity
{
    #[ORM\ManyToOne(targetEntity: MutationRelatedEntity::class)]
    private ?MutationRelatedEntity $owner = null;

    public function getOwner(): ?MutationRelatedEntity { return $this->owner; }
    public function setOwner(?MutationRelatedEntity $owner): void { $this->owner = $owner; }
}

final class MutationCollectionEntity
{
    /** @var \Doctrine\Common\Collections\Collection<int, MutationRelatedEntity> */
    #[ORM\OneToMany(targetEntity: MutationRelatedEntity::class, mappedBy: 'collection')]
    private \Doctrine\Common\Collections\Collection $members;

    /** @param list<MutationRelatedEntity> $members */
    public function __construct(array $members)
    {
        $this->members = new \Doctrine\Common\Collections\ArrayCollection($members);
    }

    /** @return \Doctrine\Common\Collections\Collection<int, MutationRelatedEntity> */
    public function getMembers(): \Doctrine\Common\Collections\Collection { return $this->members; }
    public function addMember(MutationRelatedEntity $member): void { $this->members->add($member); }
    public function removeMember(MutationRelatedEntity $member): void { $this->members->removeElement($member); }
}

final class MutationDateEntity
{
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $publishedAt = null;

    public function getPublishedAt(): ?\DateTimeInterface { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeInterface $publishedAt): void { $this->publishedAt = $publishedAt; }
}

final class MutationFakeRepository
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
            if ($match) return $entity;
        }
        return null;
    }
}

final class MutationFakeEntityManager
{
    private array $persisted = [];
    private array $removed = [];
    private int $flushCount = 0;

    public function __construct(
        private readonly MutationFakeRepository $repository,
        private readonly bool $flushThrows = false,
    ) {}

    public function getRepository(string $class): MutationFakeRepository { return $this->repository; }

    public function createQueryBuilder(): object
    {
        return new class {
            private string $dql = '';
            private array $params = [];
            public function update(string $dql, string $alias): self { $this->dql = $dql; return $this; }
            public function where(string $condition): self { return $this; }
            public function setParameter(string $name, mixed $value): self { $this->params[$name] = $value; return $this; }
            public function set(string $field, string $param): self { return $this; }
            public function getQuery(): object
            {
                return new class {
                    public function execute(): void {}
                };
            }
        };
    }

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function persisted(): array { return $this->persisted; }
    public function flushCount(): int { return $this->flushCount; }
    public function flush(): void
    {
        $this->flushCount++;
        if ($this->flushThrows) {
            throw new \RuntimeException('Flush failed');
        }
    }
    public function refresh(object $object): void {}
    public function createQuery(string $dql): object
    {
        return new class {
            private array $params = [];
            public function setParameter(string $name, mixed $value): self { $this->params[$name] = $value; return $this; }
            public function execute(): void {}
        };
    }
}

final class MutationFakeContainer implements ContainerInterface
{
    private ?SerializerInterface $serializer;

    public function __construct(
        private readonly MutationFakeEntityManager $em,
        ?SerializerInterface $serializer = null,
        private readonly ?ValidatorInterface $validator = null,
    ) {
        $this->serializer = $serializer;
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'serializer' => $this->serializer,
            'validator' => $this->validator,
            'security.token_storage' => new class {
                public function getToken(): ?object { return null; }
            },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        if ($id === 'validator') {
            return $this->validator !== null;
        }
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'serializer', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}
