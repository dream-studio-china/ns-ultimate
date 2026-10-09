<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\StoreOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoreOrder> */
class StoreOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreOrder::class);
    }

    public function findOneByTradeOrderUuid(string $tradeOrderUuid): ?StoreOrder
    {
        return $this->findOneBy(['tradeOrderUuid' => $tradeOrderUuid]);
    }

    public function findOneByUuid(string $uuid): ?StoreOrder
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
