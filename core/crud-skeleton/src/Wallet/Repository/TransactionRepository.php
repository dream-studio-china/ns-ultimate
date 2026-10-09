<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findById(int $id): ?Transaction
    {
        return $this->find($id);
    }

    public function findByUuid(string $uuid): ?Transaction
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByReferenceId(string $referenceId): ?Transaction
    {
        return $this->findOneBy(['referenceId' => $referenceId]);
    }

    /**
     * @return Transaction[]
     */
    public function findByWallet(int $walletId, int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.fromWallet = :wid OR t.toWallet = :wid')
            ->setParameter('wid', $walletId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', Transaction::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getExpectedBalance(int $walletId): int
    {
        $credits = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.toWallet = :walletId')
            ->andWhere('t.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $debits = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.fromWallet = :walletId')
            ->andWhere('t.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $credits - (int) $debits;
    }

    /**
     * Completed TYPE_DEPOSIT transactions not linked to any wallet_voucher —
     * legacy deposits created before the voucher boundary. Reported separately
     * so the boundary invariant can explain legacy balance without vouchers.
     *
     * @return array<string, int> keyed by currency
     */
    public function getUnbackedDepositsByUnit(?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('w.currency AS currency, COALESCE(SUM(t.amount), 0) AS total')
            ->join('t.toWallet', 'w')
            ->where('t.type = :type')
            ->andWhere('t.status = :status')
            ->andWhere('NOT EXISTS (SELECT v.id FROM App\Wallet\Entity\Voucher v WHERE v.walletTransactionId = t.uuid)')
            ->groupBy('w.currency')
            ->setParameter('type', Transaction::TYPE_DEPOSIT)
            ->setParameter('status', Transaction::STATUS_COMPLETED);
        if ($userId !== null) {
            $qb->andWhere('w.user = :userId')->setParameter('userId', $userId);
        }

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[$row['currency']] = (int) $row['total'];
        }

        return $result;
    }
}
