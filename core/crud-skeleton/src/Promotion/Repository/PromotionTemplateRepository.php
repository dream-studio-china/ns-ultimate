<?php

declare(strict_types=1);

namespace App\Promotion\Repository;

use App\Promotion\Entity\PromotionTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromotionTemplate>
 */
class PromotionTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromotionTemplate::class);
    }

    public function findById(int $id): ?PromotionTemplate
    {
        return $this->find($id);
    }
}
