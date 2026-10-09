<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementRule> */
class SettlementRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementRule::class);
    }

    public function findByUuid(string $uuid): ?SettlementRule
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByCode(string $code): ?SettlementRule
    {
        return $this->findOneBy(['code' => $code]);
    }
}
