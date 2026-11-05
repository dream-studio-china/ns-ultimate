<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\EventListener;

use App\Trade\Entity\Order;
use App\Store\Entity\StoreOrder;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Service\OrderRefundService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final readonly class OrderRefundGuard implements EventSubscriberInterface
{
    public function __construct(private OrderRefundService $refunds, private EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.fulfill' => 'guard', 'workflow.store_order.guard.fulfill' => 'guard'];
    }

    public function guard(GuardEvent $event): void
    {
        $order = $event->getSubject();
        $uuid = match (true) {
            $order instanceof Order => $order->getUuid(),
            $order instanceof StoreOrder => $order->getTradeOrderUuid(),
            default => null,
        };
        // Store fulfillment owns its own aggregate lock. Also lock its Trade
        // source so fulfillment and the refund reservation cannot cross.
        if ($order instanceof StoreOrder && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getRepository(Order::class)->findByUuidForUpdate($uuid);
        }
        if ($uuid !== null && $this->refunds->hasPending($uuid)) {
            $event->setBlocked(true, 'An unresolved order refund must be reconciled before fulfillment.');
        }
    }
}
