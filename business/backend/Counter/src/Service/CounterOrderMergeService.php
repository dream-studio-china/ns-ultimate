<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\DTO\StoreContext;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\LockMode;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Inventory\Service\OrderInventoryAvailability;
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
        private readonly OrderInventoryAvailability $inventory,
    ) {}

    public function merge(Counter $counter, User $user): Order
    {
        return $this->mergeOrders($counter, $user, true);
    }

    /** Store-authorized staff may merge customer orders they do not own. */
    public function mergeForStaff(Counter $counter, User $user, StoreContext $storeContext): Order
    {
        return $this->mergeOrders($counter, $user, false, $storeContext);
    }

    private function mergeOrders(Counter $counter, User $user, bool $requireOwnedSource, ?StoreContext $storeContext = null): Order
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
                if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
                    continue;
                }
                if (!$this->inventory->hasValidInventory($order)) {
                    throw new \DomainException('A valid inventory reservation is required for every source order.');
                }
                if ($order->getInvoiceId() !== null) {
                    throw new \DomainException('An order with an existing payment invoice cannot be merged.');
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
            if ($requireOwnedSource && !$eligibleUser) {
                throw new \DomainException('You must have an eligible unpaid order in this counter session to merge orders.');
            }
            if ($orders === [] || $items === []) {
                throw new \DomainException('There are no unpaid orders to merge.');
            }
            if (count($orders) < 2) {
                throw new \DomainException('At least two unpaid orders are required to merge.');
            }
            $store = $storeContext ?? $this->storeContextResolver->resolve();
            if ($store === null || $store->storeUuid !== $counter->getStoreUuid()) {
                throw new \DomainException('The counter is not in the active store context.');
            }
            $calculation = $this->orders->calculatePrices($items, $store->currency, $store->storeCode);
            // The sources have already passed store acceptance and inventory checks.
            // Do not send their merged settlement aggregate through those checks again.
            $mergeStore = new StoreContext(
                $store->storeUuid, $store->storeCode, $store->storeName, $store->channel,
                $store->currency, false, false, $store->requireVerification,
            );
            $target = $this->orders->createOrder(
                $calculation->items, $user, $calculation->totalAmount, $store->currency,
                null, ['counterUuid' => $counter->getUuid(), 'counterSessionUuid' => $session->getUuid(), 'counterSettlement' => true], $mergeStore,
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
}
