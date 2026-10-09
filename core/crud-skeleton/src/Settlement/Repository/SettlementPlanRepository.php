<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementPlan> */
class SettlementPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementPlan::class);
    }

    public function findByUuid(string $uuid): ?SettlementPlan
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByFundingId(string $fundingId): ?SettlementPlan
    {
        return $this->findOneBy(['fundingId' => $fundingId]);
    }

    public function findBySource(string $sourceType, string $sourceId, string $fundingKind = 'confirmed'): ?SettlementPlan
    {
        return $this->findOneBy([
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'fundingKind' => $fundingKind,
        ]);
    }
}
