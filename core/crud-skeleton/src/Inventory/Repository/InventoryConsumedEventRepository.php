<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryConsumedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryConsumedEvent> */
class InventoryConsumedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryConsumedEvent::class);
    }

    public function findOneByEventId(string $eventId): ?InventoryConsumedEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }
}
