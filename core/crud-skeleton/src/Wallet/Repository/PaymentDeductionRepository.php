<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Payment\Entity\Invoice;
use App\Wallet\Entity\PaymentDeduction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\PaymentDeduction>
 */
class PaymentDeductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentDeduction::class);
    }

    public function findWalletBalanceByInvoice(Invoice $invoice): ?PaymentDeduction
    {
        return $this->findOneBy(['invoiceId' => $invoice->getUuid(), 'type' => PaymentDeduction::TYPE_WALLET_BALANCE]);
    }

    public function findAppliedByInvoice(Invoice $invoice): ?PaymentDeduction
    {
        return $this->findOneBy([
            'invoiceId' => $invoice->getUuid(),
            'type' => PaymentDeduction::TYPE_WALLET_BALANCE,
            'status' => PaymentDeduction::STATUS_APPLIED,
        ]);
    }

    /** @return PaymentDeduction[] */
    public function findAppliedDeductions(Invoice $invoice): array
    {
        return $this->findBy(['invoiceId' => $invoice->getUuid(), 'status' => PaymentDeduction::STATUS_APPLIED]);
    }
}
