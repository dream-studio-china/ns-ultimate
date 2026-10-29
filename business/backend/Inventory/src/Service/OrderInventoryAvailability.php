<?php

declare(strict_types=1);

namespace NsUltimate\Business\Inventory\Service;

use App\Inventory\Entity\Reservation;
use App\Store\Entity\StoreOrder;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Entity\TradeOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Read-only reservation check; it does not lock, extend, consume or release stock. */
final class OrderInventoryAvailability
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:INVENTORY_ENABLED)%')] private readonly bool $inventoryEnabled,
    ) {}

    public function hasValidInventory(Order $order): bool
    {
        if (!$this->inventoryEnabled) {
            return true;
        }
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            return !isset(($order->getMetadata() ?? [])['_store']);
        }
        // The client's metadata is mutable; use the server-created order event.
        $created = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy([
            'aggregateId' => $order->getUuid(), 'topic' => 'trade.order.created.v1',
        ]);
        $store = $created?->getPayload()['store'] ?? null;
        if (!is_array($store) || ($store['uuid'] ?? null) !== $lifecycle->getStoreUuid() || !is_bool($store['requireInventory'] ?? null)) {
            return false;
        }
        if ($store['requireInventory'] === false) {
            return true;
        }

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')->from(Reservation::class, 'r')->from(StoreOrder::class, 's')->join('s.store', 'store')
            ->where('r.tradeOrderUuid = :orderUuid')->andWhere('r.storeUuid = :storeUuid')
            ->andWhere('s.tradeOrderUuid = r.tradeOrderUuid')->andWhere('s.uuid = r.storeOrderUuid')->andWhere('store.uuid = :storeUuid')
            ->andWhere('s.reservationId = r.reservationId')->andWhere('r.status = :status')
            ->andWhere('(r.expiresAt IS NULL OR r.expiresAt > :now)')
            ->setParameter('orderUuid', $order->getUuid())->setParameter('storeUuid', $store['uuid'])
            ->setParameter('status', Reservation::STATUS_CONFIRMED)->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
