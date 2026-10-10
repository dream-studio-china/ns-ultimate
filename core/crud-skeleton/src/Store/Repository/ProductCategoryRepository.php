<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductCategory> */
class ProductCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductCategory::class);
    }

    public function findOneByUuid(string $uuid): ?ProductCategory
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /** @return list<ProductCategory> */
    public function findReadable(?Store $store, bool $enabledOnly = true): array
    {
        $qb = $this->createQueryBuilder('category')
            ->andWhere('(category.store IS NULL OR category.store = :store)')
            ->setParameter('store', $store);
        if ($enabledOnly) {
            $qb->andWhere('category.enabled = true');
        }
        return $qb->orderBy('category.sortOrder', 'ASC')->addOrderBy('category.name', 'ASC')->getQuery()->getResult();
    }
}
