<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementAllocation> */
class SettlementAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementAllocation::class);
    }

    public function findByUuid(string $uuid): ?SettlementAllocation
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /** @return list<SettlementAllocation> */
    public function findRetryableDue(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->andWhere('a.nextAttemptAt <= :now')
            ->setParameter('status', SettlementAllocation::STATUS_RETRYABLE_FAILURE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function claimRetryDue(int $id): bool
    {
        return $this->createQueryBuilder('a')
            ->update()
            ->set('a.status', ':planned')
            ->set('a.nextAttemptAt', 'NULL')
            ->set('a.updatedAt', ':now')
            ->where('a.id = :id')
            ->andWhere('a.status = :retryable')
            ->andWhere('a.nextAttemptAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('retryable', SettlementAllocation::STATUS_RETRYABLE_FAILURE)
            ->setParameter('planned', SettlementAllocation::STATUS_PLANNED)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute() === 1;
    }

}
