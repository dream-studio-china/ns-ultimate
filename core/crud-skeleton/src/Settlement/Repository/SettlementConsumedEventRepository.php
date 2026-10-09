<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementConsumedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementConsumedEvent> */
class SettlementConsumedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementConsumedEvent::class);
    }

    public function exists(string $eventId): bool
    {
        return $this->createQueryBuilder('e')
            ->select('1')
            ->andWhere('e.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
