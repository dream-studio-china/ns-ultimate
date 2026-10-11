<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Derives the trusted Store for a payment from persisted source data.
 *
 * Client-supplied Store identifiers, request headers, and arbitrary Order
 * metadata are never trusted. Unsupported invoice sources fail closed.
 */
final class InvoiceStoreContextAdapter
{
    public const SOURCE_TRADE_ORDER = 'trade_order';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function resolveStoreUuid(Invoice $invoice): string
    {
        return match ($invoice->getSourceType()) {
            self::SOURCE_TRADE_ORDER => $this->resolveTradeOrderStore($invoice->getSourceId()),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported invoice source type "%s" for Store-scoped WeChat payment.',
                $invoice->getSourceType(),
            )),
        };
    }

    private function resolveTradeOrderStore(string $orderUuid): string
    {
        if (!UUID::is_valid($orderUuid)) {
            throw new \InvalidArgumentException('The invoice trade order identifier is not a valid UUID.');
        }

        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)
            ->findOneBy(['tradeOrderUuid' => $orderUuid]);

        $storeUuid = $lifecycle?->getStoreUuid();
        if (!is_string($storeUuid) || !UUID::is_valid($storeUuid)) {
            throw new \InvalidArgumentException('The invoice trade order has no trusted store lifecycle.');
        }

        return $storeUuid;
    }
}
