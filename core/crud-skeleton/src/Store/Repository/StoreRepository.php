<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\Store;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Store> */
class StoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Store::class);
    }

    public function findOneByUuid(string $uuid): ?Store
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByCode(string $code): ?Store
    {
        return $this->findOneBy(['code' => $code]);
    }
}
