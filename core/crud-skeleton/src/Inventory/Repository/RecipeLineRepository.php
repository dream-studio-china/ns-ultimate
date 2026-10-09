<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\RecipeLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RecipeLine> */
class RecipeLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecipeLine::class);
    }
}
