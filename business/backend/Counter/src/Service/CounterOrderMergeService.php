<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\DTO\StoreContext;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\LockMode;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Inventory\Service\OrderInventoryAvailability;
use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use NsUltimate\Business\Store\Service\SalesAvailabilityScope;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class CounterOrderMergeService
{
    private const UNPAID_INVOICE_STATUSES = [
        Invoice::STATUS_PENDING, Invoice::STATUS_PAYING, Invoice::STATUS_FAILED, Invoice::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderServiceInterface $orders,
        private readonly StoreContextResolverInterface $storeContextResolver,
        #[Target('state_machine.order')]
        private readonly WorkflowInterface $orderWorkflow,
        private readonly CounterSessionService $sessions,
        private readonly OrderInventoryAvailability $inventory,
        private readonly SalesAvailabilityScope $salesAvailabilityScope,
    ) {}

    public function merge(Counter $counter, User $user): Order
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $store = $this->storeContextResolver->resolve();
            if ($store === null || $store->storeUuid !== $counter->getStoreUuid()) {
                throw new \DomainException('The counter is not in the active store context.');
            }
            $session = $this->sessions->activeSession($counter);
            if (!$session instanceof CounterSession) {
                throw new \DomainException('There is no active session for this counter.');
            }
            $bindings = $this->em->getRepository(OrderCounter::class)->findBy(['counterSession' => $session], ['tradeOrderUuid' => 'ASC']);
            $orders = [];
            $items = [];
            $lock = $this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform
                ? LockMode::NONE : LockMode::PESSIMISTIC_WRITE;
            foreach ($bindings as $binding) {
                $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $binding->getTradeOrderUuid()]);
                if (!$order instanceof Order) {
                    throw new \LogicException('A counter order binding references a missing order.');
                }
                $this->em->refresh($order, $lock);
                if (isset($orders[$order->getUuid()])) {
                    continue;
                }
                if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
                    continue;
                }
                if (!$this->inventory->hasValidInventory($order)) {
                    throw new \DomainException('A valid inventory reservation is required for every source order.');
                }
                $this->assertUnpaid($order, $lock);
                if ($this->em->getRepository(OrderCounter::class)->count(['tradeOrderUuid' => $order->getUuid()]) !== 1) {
                    throw new \DomainException('Orders bound to multiple counters cannot be merged.');
                }
                $orders[$order->getUuid()] = $order;
                foreach ($order->getItems() as $item) {
                    if (!$item instanceof OrderItem || $item->getSpecificationUuid() === null) {
                        throw new \DomainException('Every order item must have a specification UUID to merge.');
                    }
                    $items[] = ['specificationId' => $item->getSpecificationUuid(), 'quantity' => $item->getQuantity()];
                }
            }
            if ($orders === [] || $items === []) {
                throw new \DomainException('There are no unpaid orders to merge.');
            }
            if (count($orders) < 2) {
                throw new \DomainException('At least two unpaid orders are required to merge.');
            }
            $calculation = $this->salesAvailabilityScope->forMerge(
                fn () => $this->orders->calculatePrices($items, $store->currency, $store->storeCode),
            );
            // Preserve the ordinary Store order flow, but do not reserve inventory
            // a second time for items already present in the source orders.
            $mergeStore = new StoreContext(
                $store->storeUuid, $store->storeCode, $store->storeName, $store->channel,
                $store->currency, $store->requireAcceptance, false, $store->requireVerification,
            );
            $target = $this->orders->createOrder(
                $calculation->items, $user, $calculation->totalAmount, $store->currency,
                null, OrderPromotionSnapshot::replace(
                    ['counterUuid' => $counter->getUuid(), 'counterSessionUuid' => $session->getUuid(), 'counterSettlement' => true],
                    OrderPromotionSnapshot::fromPricingMeta($calculation->meta),
                ), $mergeStore,
            );
            foreach ($orders as $source) {
                $this->em->persist(new OrderMerge($source->getUuid(), $target->getUuid()));
            }
            $this->em->flush();
            foreach ($orders as $source) {
                $this->orderWorkflow->apply($source, 'merge');
                $source->setMetadata([
                    ...($source->getMetadata() ?? []),
                    'mergedIntoOrderUuid' => $target->getUuid(),
                ]);
            }
            $this->em->flush();
            if ($this->orderWorkflow->can($target, 'confirm')) {
                $this->orderWorkflow->apply($target, 'confirm');
            }
            $this->em->flush();
            $this->em->getConnection()->commit();
            return $target;
        } catch (\Throwable $error) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $error;
        }
    }

    private function assertUnpaid(Order $order, LockMode $lock): void
    {
        if ($order->getPaidAt() !== null || $order->getRefundedAt() !== null
            || ($order->getPaymentStatus() !== null && !in_array($order->getPaymentStatus(), self::UNPAID_INVOICE_STATUSES, true))) {
            throw new \DomainException('An order with a recorded payment or refund cannot be merged.');
        }

        // Match Core's lock order (source Order, then Invoice). A locking read also
        // avoids using an earlier transaction snapshot of the source's invoices.
        $query = $this->em->createQueryBuilder()->select('i')->from(Invoice::class, 'i')
            ->where('i.sourceType = :type AND i.sourceId = :source')
            ->setParameter('type', 'trade_order')->setParameter('source', $order->getUuid())
            ->orderBy('i.id', 'ASC')->getQuery()->setHint(Query::HINT_REFRESH, true);
        if ($lock !== LockMode::NONE) {
            $query->setLockMode($lock);
        }
        $linkedInvoiceFound = $order->getInvoiceId() === null;
        foreach ($query->getResult() as $invoice) {
            if ($invoice->getUuid() === $order->getInvoiceId()) {
                $linkedInvoiceFound = true;
            }
            if (!in_array($invoice->getStatus(), self::UNPAID_INVOICE_STATUSES, true)
                || $invoice->getPaidAt() !== null || $invoice->getRefundedAmount() !== 0
                || $invoice->getTransactionId() !== null
                || isset($invoice->getExtraData()['payment_close_collection'])) {
                throw new \DomainException('An order with a recorded payment or refund cannot be merged.');
            }
        }
        if (!$linkedInvoiceFound) {
            throw new \DomainException('An order with an inconsistent payment invoice link cannot be merged.');
        }
    }
}
