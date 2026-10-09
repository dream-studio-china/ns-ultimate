<?php

declare(strict_types=1);

namespace App\Payment\Repository;

use App\Payment\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Payment\Entity\Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findOneByOutTradeNo(string $outTradeNo): ?Invoice
    {
        return $this->findOneBy(['outTradeNo' => $outTradeNo]);
    }

    /** @return Invoice[] */
    public function findBySource(string $sourceType, string $sourceId): array
    {
        return $this->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId], ['id' => 'DESC']);
    }
}
