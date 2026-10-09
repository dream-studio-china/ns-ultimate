<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Trade\Entity\OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    public function findById(int $id): ?OrderItem
    {
        return $this->find($id);
    }

    /**
     * @return list<OrderItem>
     */
    public function findByOrder(int $orderId): array
    {
        return $this->findBy(['order' => $orderId]);
    }
}
