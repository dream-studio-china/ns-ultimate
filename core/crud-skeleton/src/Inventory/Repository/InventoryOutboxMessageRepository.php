<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryOutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryOutboxMessage> */
class InventoryOutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryOutboxMessage::class);
    }

    /** @return list<InventoryOutboxMessage> */
    public function findUnpublished(int $limit = 100): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.availableAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('message.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<array{id: int, eventId: string, topic: string, aggregateId: string, payload: array<string, mixed>}> */
    public function findUnpublishedForPublishing(int $limit = 100): array
    {
        /** @var list<array{id: int, eventId: string, topic: string, aggregateId: string, payload: array<string, mixed>}> $messages */
        $messages = $this->createQueryBuilder('message')
            ->select('message.id, message.eventId, message.topic, message.aggregateId, message.payload')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.availableAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('message.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $messages;
    }

    public function markPublished(int $id): void
    {
        $this->createQueryBuilder('message')
            ->update()
            ->set('message.publishedAt', ':publishedAt')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('publishedAt', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    public function claim(int $id, \DateTimeImmutable $until): bool
    {
        return $this->createQueryBuilder('message')
            ->update()
            ->set('message.availableAt', ':until')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.availableAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('until', $until)
            ->getQuery()
            ->execute() === 1;
    }

    public function recordAttempt(int $id, string $error, \DateTimeImmutable $availableAt): void
    {
        $this->createQueryBuilder('message')
            ->update()
            ->set('message.attempts', 'message.attempts + 1')
            ->set('message.lastError', ':error')
            ->set('message.availableAt', ':availableAt')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('error', $error)
            ->setParameter('availableAt', $availableAt)
            ->getQuery()
            ->execute();
    }
}
