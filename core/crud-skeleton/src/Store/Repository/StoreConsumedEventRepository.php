<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\StoreConsumedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoreConsumedEvent> */
class StoreConsumedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreConsumedEvent::class);
    }

    public function findOneByEventId(string $eventId): ?StoreConsumedEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }
}
