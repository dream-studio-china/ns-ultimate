<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\SpecificationRecipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SpecificationRecipe> */
class SpecificationRecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpecificationRecipe::class);
    }

    public function findActiveBySpecificationUuid(string $uuid): ?SpecificationRecipe
    {
        return $this->findOneBy([
            'specificationUuid' => $uuid,
            'status' => SpecificationRecipe::STATUS_ACTIVE,
        ]);
    }
}
