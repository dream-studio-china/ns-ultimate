<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Configuration;

use App\Payment\Entity\Invoice;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;

final class HuifuStoreContextResolver
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function storeUuid(Invoice $invoice): string
    {
        if ($invoice->getSourceType() !== 'trade_order') {
            throw new \RuntimeException('Huifu payments require a store-scoped trade order invoice.');
        }
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)
            ->findOneBy(['tradeOrderUuid' => $invoice->getSourceId()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            throw new \RuntimeException('The trade order has no trusted Store context.');
        }

        return $lifecycle->getStoreUuid();
    }

    public function assertInvoiceBelongsToStore(Invoice $invoice, string $storeUuid): void
    {
        if ($this->storeUuid($invoice) !== $storeUuid) {
            throw new \RuntimeException('The invoice does not belong to this Store.');
        }
    }
}
