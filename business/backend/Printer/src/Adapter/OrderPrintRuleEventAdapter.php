<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Service\OrderPrintRuleEnqueuer;
use Psr\Log\LoggerInterface;

/**
 * Resolve the trusted order/store association before evaluating print rules.
 *
 * Printing is secondary to the order lifecycle: a broken print configuration or
 * storage error must never roll back a payment, merge or confirmation. Failures
 * are logged and the affected rule stays unclaimed, so a later event can retry.
 */
final class OrderPrintRuleEventAdapter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPrintRuleEnqueuer $enqueuer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function onConfirmed(Order $order): void
    {
        if ($order->getStatus() === Order::STATUS_CONFIRMED) {
            $this->safely($order, 'confirmed', fn () => $this->enqueueForOrder($order, 'confirmed'));
        }
    }

    public function onPaid(Order $order): void
    {
        if ($order->getStatus() === Order::STATUS_PAID) {
            $this->safely($order, 'paid', fn () => $this->enqueueForOrder($order, 'paid'));
        }
    }

    public function onAccepted(string $orderUuid, string $storeUuid): void
    {
        $this->safely(null, 'accepted', function () use ($orderUuid, $storeUuid): void {
            $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $orderUuid]);
            if (!$lifecycle instanceof OrderStoreLifecycle || $lifecycle->getStoreUuid() !== $storeUuid) {
                return;
            }
            $order = $this->entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
            $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
            if ($order instanceof Order && $store instanceof Store) {
                $this->enqueuer->enqueue($order, $store, 'accepted');
            }
        });
    }

    private function enqueueForOrder(Order $order, string $event): void
    {
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            return;
        }
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $lifecycle->getStoreUuid()]);
        if ($store instanceof Store) {
            $this->enqueuer->enqueue($order, $store, $event);
        }
    }

    /** Run inside the caller's transaction or a dedicated one; never propagate print failures. */
    private function safely(?Order $order, string $event, callable $work): void
    {
        try {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $work();
            } else {
                $this->entityManager->wrapInTransaction($work);
            }
        } catch (\Throwable $exception) {
            $this->logger?->error('Order print rule enqueue failed.', [
                'event' => $event,
                'orderUuid' => $order?->getUuid(),
                'exception' => $exception::class,
                'message' => substr($exception->getMessage(), 0, 500),
            ]);
        }
    }
}
