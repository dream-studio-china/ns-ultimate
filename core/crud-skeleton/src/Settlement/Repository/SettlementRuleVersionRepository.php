<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Entity\SettlementRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SettlementRuleVersion> */
class SettlementRuleVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementRuleVersion::class);
    }

    /**
     * Published, effective rule versions at the given instant, ordered by
     * priority then rule UUID for determinism. Source-tag matching is decided
     * in memory so the query stays portable (no DB-specific JSON functions).
     *
     * @return list<SettlementRuleVersion>
     */
    public function findActiveAt(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin(SettlementRule::class, 'r', 'WITH', 'r.uuid = v.ruleUuid')
            ->andWhere('v.status = :status')
            ->andWhere('r.status = :ruleStatus')
            ->andWhere('v.effectiveFrom <= :asOf')
            ->andWhere('v.effectiveTo IS NULL OR v.effectiveTo > :asOf')
            ->setParameter('status', SettlementRuleVersion::STATUS_PUBLISHED)
            ->setParameter('ruleStatus', SettlementRule::STATUS_PUBLISHED)
            ->setParameter('asOf', $asOf)
            ->orderBy('v.priority', 'ASC')
            ->addOrderBy('v.ruleUuid', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUuid(string $uuid): ?SettlementRuleVersion
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function nextVersionForRule(string $ruleUuid): int
    {
        $latest = $this->findOneBy(['ruleUuid' => $ruleUuid], ['version' => 'DESC']);

        return ($latest?->getVersion() ?? 0) + 1;
    }

    public function hasOverlappingPublishedVersion(SettlementRuleVersion $candidate): bool
    {
        $query = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.ruleUuid = :ruleUuid')
            ->andWhere('v.uuid != :uuid')
            ->andWhere('v.status = :status')
            ->andWhere('(v.effectiveTo IS NULL OR v.effectiveTo > :candidateFrom)')
            ->setParameter('ruleUuid', $candidate->getRuleUuid())
            ->setParameter('uuid', $candidate->getUuid())
            ->setParameter('status', SettlementRuleVersion::STATUS_PUBLISHED)
            ->setParameter('candidateFrom', $candidate->getEffectiveFrom());

        if ($candidate->getEffectiveTo() !== null) {
            $query
                ->andWhere('v.effectiveFrom < :candidateTo')
                ->setParameter('candidateTo', $candidate->getEffectiveTo());
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }
}
