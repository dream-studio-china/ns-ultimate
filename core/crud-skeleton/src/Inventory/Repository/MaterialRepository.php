<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\Material;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Material> */
class MaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Material::class);
    }

    public function findOneByUuid(string $uuid): ?Material
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByUuidForUpdate(string $uuid): ?Material
    {
        return $this->createQueryBuilder('material')
            ->andWhere('material.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findActiveFinishedByCode(string $code): ?Material
    {
        return $this->findOneBy([
            'code' => $code,
            'kind' => Material::KIND_FINISHED,
            'status' => Material::STATUS_ACTIVE,
        ]);
    }
}
