<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Core\Service\QueryBuilderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Store\Entity\Product;

/** Keeps root products visible while exposing subtype fields for ordering. */
final class ProductQueryBuilderFactory extends QueryBuilderFactory
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function createRootQueryBuilder(string $dataClass, string $rootAlias = 'entity'): QueryBuilder
    {
        $queryBuilder = parent::createRootQueryBuilder($dataClass, $rootAlias);
        self::joinExtension($queryBuilder);

        return $queryBuilder;
    }

    public static function joinExtension(QueryBuilder $queryBuilder): void
    {
        if (in_array('storeProductExtension', $queryBuilder->getAllAliases(), true)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->leftJoin(Product::class, 'storeProductExtension', 'WITH', $rootAlias.'.id = storeProductExtension.id');
    }
}
