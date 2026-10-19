<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use NsUltimate\Business\Store\Entity\Product;

/** @extends ServiceEntityRepository<Product> */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }
}
