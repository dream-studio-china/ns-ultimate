<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\StoreTradeOrderCancellation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoreTradeOrderCancellation> */
final class StoreTradeOrderCancellationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreTradeOrderCancellation::class);
    }

    public function findOneByTradeOrderUuid(string $tradeOrderUuid): ?StoreTradeOrderCancellation
    {
        return $this->findOneBy(['tradeOrderUuid' => $tradeOrderUuid]);
    }
}
