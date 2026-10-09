<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\Stock;
use App\Inventory\Entity\Material;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Stock> */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    public function findOneByStoreAndMaterial(string $storeUuid, Material $material): ?Stock
    {
        return $this->findOneBy([
            'storeUuid' => $storeUuid,
            'material' => $material,
        ]);
    }
    public function findOneByStoreAndMaterialForUpdate(string $storeUuid, Material $material): ?Stock
    {
        return $this->createQueryBuilder('stock')
            ->andWhere('stock.storeUuid = :storeUuid')
            ->andWhere('stock.material = :material')
            ->setParameter('storeUuid', $storeUuid)
            ->setParameter('material', $material)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
