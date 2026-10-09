<?php

declare(strict_types=1);

namespace App\Promotion\Repository;

use App\Promotion\Entity\Promotion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Promotion\Entity\PromotionTemplate;

/**
 * @extends ServiceEntityRepository<Promotion>
 */
class PromotionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promotion::class);
    }

    public function findById(int $id): ?Promotion
    {
        return $this->find($id);
    }

    /**
     * @return Promotion[]
     * @param int[] $excludedIds
     */
    public function findActiveForStore(string $storeCode, \DateTimeImmutable $now, ?int $phase, array $excludedIds = []): array
    {
        $qb = $this->createQueryBuilder('promotion')
            ->innerJoin('promotion.template', 'template')
            ->addSelect('template')
            ->andWhere('promotion.enabled = :enabled')
            ->andWhere('template.enabled = :templateEnabled')
            ->andWhere('(promotion.startTime IS NULL OR promotion.startTime <= :now)')
            ->andWhere('(promotion.endTime IS NULL OR promotion.endTime >= :now)')
            ->setParameter('enabled', true)
            ->setParameter('templateEnabled', true)
            ->setParameter('now', $now);

        if ($storeCode === '') {
            $qb->andWhere('promotion.storeCode = :globalStoreCode')
                ->setParameter('globalStoreCode', '');
        } else {
            $qb->andWhere('(promotion.storeCode = :storeCode OR promotion.storeCode = :globalStoreCode)')
                ->setParameter('storeCode', $storeCode)
                ->setParameter('globalStoreCode', '');
        }

        if ($phase !== null) {
            $qb->andWhere('template.phase = :phase')->setParameter('phase', $phase);
        }

        if ($excludedIds !== []) {
            $qb->andWhere($qb->expr()->notIn('promotion.id', ':excludedIds'))
                ->setParameter('excludedIds', $excludedIds);
        }

        return $qb->getQuery()->getResult();
    }
}
