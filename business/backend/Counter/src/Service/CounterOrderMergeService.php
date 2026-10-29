<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\LockMode;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class CounterOrderMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderServiceInterface $orders,
        private readonly StoreContextResolverInterface $storeContextResolver,
        #[Target('state_machine.order')]
        private readonly WorkflowInterface $orderWorkflow,
        private readonly CounterSessionService $sessions,
    ) {}

    public function merge(Counter $counter, User $user): Order
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $session = $this->sessions->activeSession($counter);
            if (!$session instanceof CounterSession) {
                throw new \DomainException('There is no active session for this counter.');
            }
            $bindings = $this->em->getRepository(OrderCounter::class)->findBy(['counterSession' => $session], ['tradeOrderUuid' => 'ASC']);
            $orders = [];
            $items = [];
            $eligibleUser = false;
            foreach ($bindings as $binding) {
                $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $binding->getTradeOrderUuid()]);
                if (!$order instanceof Order) {
                    throw new \LogicException('A counter order binding references a missing order.');
                }
                $lock = $this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform
                    ? LockMode::NONE : LockMode::PESSIMISTIC_WRITE;
                $this->em->refresh($order, $lock);
                if (isset($orders[$order->getUuid()])) {
                    continue;
                }
                if (!in_array($order->getStatus(), [Order::STATUS_PENDING, Order::STATUS_CONFIRMED], true)) {
                    continue;
                }
                if ($order->getInvoiceId() !== null) {
                    throw new \DomainException('An order with an existing payment invoice cannot be merged.');
                }
                if ($this->em->getRepository(OrderMerge::class)->count(['targetOrderUuid' => $order->getUuid()]) > 0) {
                    throw new \DomainException('A merged settlement cannot be merged again.');
                }
                $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
                if (!$lifecycle instanceof OrderStoreLifecycle || $lifecycle->getAcceptanceStatus() !== OrderStoreLifecycle::ACCEPTANCE_ACCEPTED) {
                    throw new \DomainException('Every source order must be accepted by the store before merging.');
                }
                if ($this->em->getRepository(OrderCounter::class)->count(['tradeOrderUuid' => $order->getUuid()]) !== 1) {
                    throw new \DomainException('Orders bound to multiple counters cannot be merged.');
                }
                if ($order->getUser()?->getId() === $user->getId()) {
                    $eligibleUser = true;
                }
                $orders[$order->getUuid()] = $order;
                foreach ($order->getItems() as $item) {
                    if (!$item instanceof OrderItem || $item->getSpecificationUuid() === null) {
                        throw new \DomainException('Every order item must have a specification UUID to merge.');
                    }
                    $items[] = ['specificationId' => $item->getSpecificationUuid(), 'quantity' => $item->getQuantity()];
                }
            }
            if (!$eligibleUser) {
                throw new \DomainException('You must have an eligible unpaid order in this counter session to merge orders.');
            }
            if ($orders === [] || $items === []) {
                throw new \DomainException('There are no unpaid orders to merge.');
            }
            if (count($orders) < 2) {
                throw new \DomainException('At least two unpaid orders are required to merge.');
            }
            $store = $this->storeContextResolver->resolve();
            if ($store === null || $store->storeUuid !== $counter->getStoreUuid()) {
                throw new \DomainException('The counter is not in the active store context.');
            }
            $calculation = $this->orders->calculatePrices($items, $store->currency, $store->storeCode);
            // A settlement order is not a new fulfillment order. In particular, do
            // not pass a StoreContext to createOrder: that would emit a second
            // trade.order.created event and reserve the same inventory again.
            $snapshot = $store->toSnapshot();
            $snapshot['requireAcceptance'] = false;
            $target = $this->orders->createOrder(
                $calculation->items, $user, $calculation->totalAmount, $store->currency,
                null, ['_store' => $snapshot, 'counterSessionUuid' => $session->getUuid(), 'counterSettlement' => true], null,
            );
            $this->em->persist(new OrderStoreLifecycle($target->getUuid(), $store->storeUuid));
            $this->em->persist((new OrderCounter())->setTradeOrderUuid($target->getUuid())->setCounter($counter)->setStoreUuid($store->storeUuid));
            $this->orderWorkflow->apply($target, 'submit');
            $this->orderWorkflow->apply($target, 'confirm');
            foreach ($orders as $source) {
                $this->orderWorkflow->apply($source, 'merge');
                $source->setMetadata([
                    ...($source->getMetadata() ?? []),
                    'mergedIntoOrderUuid' => $target->getUuid(),
                    // Source Store fulfillment and inventory reservations are not transferred yet.
                    'counterMergeInventoryReconciliationRequired' => true,
                ]);
                $this->em->persist(new OrderMerge($source->getUuid(), $target->getUuid()));
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
}
