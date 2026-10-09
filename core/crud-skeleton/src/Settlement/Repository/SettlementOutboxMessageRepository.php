<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementOutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementOutboxMessage> */
class SettlementOutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementOutboxMessage::class);
    }

    /** @return list<SettlementOutboxMessage> */
    public function findUnpublished(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.publishedAt IS NULL')
            ->andWhere('m.availableAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function claim(int $id, \DateTimeImmutable $until): bool
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.availableAt', ':until')
            ->where('m.id = :id')
            ->andWhere('m.publishedAt IS NULL')
            ->andWhere('m.availableAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('until', $until)
            ->getQuery()
            ->execute() === 1;
    }

    public function defer(int $id, string $error, \DateTimeImmutable $availableAt): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.attempts', 'm.attempts + 1')
            ->set('m.lastError', ':error')
            ->set('m.availableAt', ':availableAt')
            ->where('m.id = :id')
            ->andWhere('m.publishedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('error', $error)
            ->setParameter('availableAt', $availableAt)
            ->getQuery()
            ->execute();
    }
}
