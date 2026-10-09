<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Store\Entity\Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findById(int $id): ?Product
    {
        return $this->find($id);
    }

    /**
     * @return list<Product>
     */
    public function findNotDeleted(): array
    {
        return $this->findBy(['isDeleted' => false]);
    }

    /**
     * @return list<Product>
     */
    public function findActive(): array
    {
        return $this->findBy(['status' => Product::STATUS_ACTIVE, 'isDeleted' => false]);
    }

    /**
     * @return list<Product>
     */
    public function findVisibleForStore(?int $storeId): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isDeleted = :isDeleted')
            ->andWhere('p.status = :status')
            ->setParameter('isDeleted', false)
            ->setParameter('status', Product::STATUS_ACTIVE);

        if ($storeId === null) {
            $qb->andWhere('p.store IS NULL');
        } else {
            $qb->andWhere('p.store IS NULL OR p.store = :storeId')
                ->setParameter('storeId', $storeId);
        }

        return $qb->getQuery()->getResult();
    }
}
