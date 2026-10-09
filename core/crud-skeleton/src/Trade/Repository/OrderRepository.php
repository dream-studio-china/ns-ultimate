<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Trade\Entity\Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findById(int $id): ?Order
    {
        return $this->find($id);
    }

    /**
     * @return list<Order>
     */
    public function findByUser(int $userId): array
    {
        return $this->findBy(['user' => $userId], ['createdAt' => 'DESC']);
    }
}
