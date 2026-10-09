<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Wechat\Repository;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class WechatUserRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;
    private ManagerRegistry $registry;
    private WechatUserRepository $repository;

    protected function setUp(): void
    {
        $metadata = new ClassMetadata(WechatUser::class);
        $metadata->setIdentifier(['id']);

        $config = $this->createMock(Configuration::class);
        $config->method('getDefaultQueryHints')->willReturn([]);
        $config->method('isSecondLevelCacheEnabled')->willReturn(false);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($this->createMock(Query::class));

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->em->method('getConfiguration')->willReturn($config);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')
            ->with(WechatUser::class)
            ->willReturn($this->em);

        $this->repository = new WechatUserRepository($this->registry);
    }

    public function testFindByOpenidReturnsNullWhenNoMatch(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $result = $this->repository->findByOpenid('nonexistent');
        self::assertNull($result);
    }

    public function testFindByUserReturnsNullWhenNoMatch(): void
    {
        $user = new User();

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $result = $this->repository->findByUser($user);
        self::assertNull($result);
    }

    public function testRepositoryIsProperlyInitialized(): void
    {
        self::assertInstanceOf(WechatUserRepository::class, $this->repository);
    }
}
