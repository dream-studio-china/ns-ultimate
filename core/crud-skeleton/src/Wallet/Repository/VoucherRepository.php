<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Voucher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\Voucher>
 */
class VoucherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voucher::class);
    }

    public function findByUuid(string $uuid): ?Voucher
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByReferenceId(string $referenceId): ?Voucher
    {
        return $this->findOneBy(['referenceId' => $referenceId]);
    }

    public function findByVoucherSource(string $voucherType, string $voucherId): ?Voucher
    {
        return $this->findOneBy(['voucherType' => $voucherType, 'voucherId' => $voucherId]);
    }

    /**
     * @return Voucher[]
     */
    public function findAppliedByWallet(int $walletId, int $limit = 50): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.wallet = :walletId')
            ->andWhere('v.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', Voucher::STATUS_APPLIED)
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Voucher[]
     */
    public function findForReconciliation(
        string $currency,
        ?string $fundSource = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        $qb = $this->createQueryBuilder('v')
            ->where('v.currency = :currency')
            ->andWhere('v.status = :status')
            ->orderBy('v.createdAt', 'ASC')
            ->setParameter('currency', strtoupper($currency))
            ->setParameter('status', Voucher::STATUS_APPLIED);
        if ($fundSource !== null) {
            $qb->andWhere('v.fundSource = :fundSource')->setParameter('fundSource', $fundSource);
        }
        if ($from !== null) {
            $qb->andWhere('v.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('v.createdAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Boundary invariant per unit of account:
     * SUM(applied credit vouchers) - SUM(applied debit vouchers).
     *
     * @return array<string, int> keyed by currency
     */
    public function getBoundaryTotalByUnit(?int $userId = null): array
    {
        $credits = $this->sumAppliedByUnit(Voucher::DIRECTION_CREDIT, $userId);
        $debits = $this->sumAppliedByUnit(Voucher::DIRECTION_DEBIT, $userId);

        $currencies = array_unique(array_merge(array_keys($credits), array_keys($debits)));
        sort($currencies);

        $result = [];
        foreach ($currencies as $currency) {
            $result[$currency] = ($credits[$currency] ?? 0) - ($debits[$currency] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, int> applied voucher amount per currency for a direction
     */
    private function sumAppliedByUnit(string $direction, ?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.currency AS currency, COALESCE(SUM(v.amount), 0) AS total')
            ->where('v.direction = :direction')
            ->andWhere('v.status = :status')
            ->groupBy('v.currency')
            ->setParameter('direction', $direction)
            ->setParameter('status', Voucher::STATUS_APPLIED);
        if ($userId !== null) {
            $qb->join('v.wallet', 'w')
                ->andWhere('w.user = :userId')
                ->setParameter('userId', $userId);
        }

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[$row['currency']] = (int) $row['total'];
        }

        return $result;
    }
}
