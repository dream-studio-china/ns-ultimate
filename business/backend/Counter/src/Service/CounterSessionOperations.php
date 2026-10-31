<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Inventory\Entity\Reservation;
use App\Store\DTO\StoreSettings;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Service\Pricing\PriceCalculationResult;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Store\Service\StaffCommandService;
use NsUltimate\Business\Store\Service\StaffOrderPricing;
use NsUltimate\Business\Store\Service\StaffOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Store counter installation operations: the active service session, its participants, and orders
 * bound to that session. Reusable order pricing and writes are delegated to the Store module.
 */
final class CounterSessionOperations
{
    private const CLOSED = ['completed', 'cancelled', 'refunded', 'merged'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CounterSessionService $sessions,
        private readonly DiningModeSnapshotResolver $diningModes,
        private readonly StaffOrderPricing $pricing,
        private readonly StaffOrderService $orders,
        private readonly StoreOrderServiceInterface $storeOrders,
        #[Target('state_machine.order')] private readonly WorkflowInterface $workflow,
    ) {}

    public function counter(Store $store, string $uuid, bool $lock = false): Counter
    {
        $counter = $this->em->getRepository(Counter::class)->findOneBy(['uuid' => $uuid, 'storeUuid' => $store->getUuid()]);
        if (!$counter instanceof Counter) throw new HttpException(404, 'counter_not_found');
        if ($lock) { $this->sessions->lockCounter($counter); $this->em->refresh($counter); }
        if ($counter->getStoreUuid() !== $store->getUuid()) throw new HttpException(404, 'counter_not_found');
        return $counter;
    }

    /** Locking queries must use current reads, not an earlier MySQL REPEATABLE READ snapshot.
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @return T|null */
    private function findCurrent(string $class, array $criteria, bool $lock): ?object
    {
        if (!$lock) return $this->em->getRepository($class)->findOneBy($criteria);
        $builder = $this->em->getRepository($class)->createQueryBuilder('record');
        foreach ($criteria as $field => $value) $builder->andWhere('record.'.$field.' = :'.$field)->setParameter($field, $value);
        return $builder->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    /**
     * Full state, including order rows. Response redaction is applied by the controller.
     * @return array<string, mixed>
     */
    public function view(Counter $counter, bool $lock = false): array
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $counter->getStoreUuid()]);
        if (!$store instanceof Store) throw new HttpException(409, 'invalid_counter_store');
        $session = $lock ? $this->sessions->activeSession($counter) : $this->em->getRepository(CounterSession::class)->findOneBy(['counter' => $counter, 'endedAt' => null]);
        $rows = []; $openAmount = 0; $orderedAmount = 0; $paymentHold = false; $openOrders = 0;
        if ($session instanceof CounterSession) {
            $query = $this->em->getRepository(OrderCounter::class)->createQueryBuilder('binding')->where('binding.counterSession = :session')
                ->setParameter('session', $session)->orderBy('binding.tradeOrderUuid', 'ASC')->setMaxResults(201)->getQuery();
            if ($lock) $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
            $bindings = $query->getResult();
            if (count($bindings) > 200) throw new HttpException(409, 'counter_session_limit');
            foreach ($bindings as $binding) {
                if ($binding->getStoreUuid() !== $counter->getStoreUuid()) throw new HttpException(409, 'invalid_counter_binding');
                $order = $this->findCurrent(Order::class, ['uuid' => $binding->getTradeOrderUuid()], $lock);
                $lifecycle = $this->findCurrent(OrderStoreLifecycle::class, ['tradeOrderUuid' => $binding->getTradeOrderUuid(), 'storeUuid' => $counter->getStoreUuid()], $lock);
                if (!$order instanceof Order || !$lifecycle instanceof OrderStoreLifecycle) throw new HttpException(409, 'invalid_counter_binding');
                if ($order->getCurrency() !== $store->getCurrency() || $order->getTotalAmount() < 0) throw new HttpException(409, 'counter_currency_conflict');
                $storeOrder = $this->findCurrent(StoreOrder::class, ['tradeOrderUuid' => $order->getUuid()], $lock);
                if ($storeOrder !== null && $storeOrder->getStore()->getUuid() !== $counter->getStoreUuid()) throw new HttpException(409, 'invalid_counter_binding');
                $items = [];
                foreach ($order->getItems() as $item) {
                    $productName = $item->getProductSnapshot()['name'] ?? '';
                    $items[] = ['specificationUuid' => $item->getSpecificationUuid(), 'productName' => is_string($productName) ? $productName : '',
                        'specificationName' => $item->getSpecificationTitle() ?? '', 'quantity' => $item->getQuantity(), 'unitPrice' => $item->getUnitPrice(), 'amount' => $item->getPrice()];
                }
                $rows[] = ['uuid' => $order->getUuid(), 'status' => $order->getStatus(), 'operationalStatus' => $storeOrder?->getOperationalStatus(),
                    'paymentStatus' => $order->getPaymentStatus(), 'hasInvoice' => $order->getInvoiceId() !== null,
                    'currency' => $order->getCurrency(), 'totalAmount' => $order->getTotalAmount(), 'notes' => $order->getNotes(),
                    'createdAt' => $order->getCreatedAt()->format(DATE_ATOM), 'items' => $items,
                    'canReplace' => $this->canReplace($order, $lifecycle, $storeOrder), 'canCancel' => $this->canCancel($order, $lifecycle, $storeOrder),
                    'changeBlockedReason' => $this->changeBlockedReason($order, $lifecycle, $storeOrder),
                    'replaceBlockedReason' => $this->canReplace($order, $lifecycle, $storeOrder) ? null : ($this->changeBlockedReason($order, $lifecycle, $storeOrder) ?? 'unsupported_order_items')];
                if (!in_array($order->getStatus(), ['cancelled', 'refunded', 'merged'], true)) $orderedAmount += $order->getTotalAmount();
                if (!in_array($order->getStatus(), self::CLOSED, true)) ++$openOrders;
                if (in_array($order->getStatus(), ['draft', 'pending', 'confirmed'], true)) {
                    $openAmount += $order->getTotalAmount();
                    $paymentHold = $paymentHold || $order->getInvoiceId() !== null;
                }
            }
            // Only expose merge links whose source and target are both bound to
            // this session; never trust mutable order metadata for the link.
            $indexes = [];
            foreach ($rows as $index => $row) {
                $indexes[$row['uuid']] = $index;
                $rows[$index]['mergedIntoOrderUuid'] = null;
                $rows[$index]['mergedSourceOrderUuids'] = [];
            }
            if ($indexes !== []) {
                $links = $this->em->createQueryBuilder()->select('link.sourceOrderUuid', 'link.targetOrderUuid')
                    ->from(OrderMerge::class, 'link')
                    ->where('link.sourceOrderUuid IN (:orders)')->andWhere('link.targetOrderUuid IN (:orders)')
                    ->orderBy('link.sourceOrderUuid', 'ASC')
                    ->setParameter('orders', array_keys($indexes))->getQuery()->getArrayResult();
                foreach ($links as $link) {
                    $rows[$indexes[$link['sourceOrderUuid']]]['mergedIntoOrderUuid'] = $link['targetOrderUuid'];
                    $rows[$indexes[$link['targetOrderUuid']]]['mergedSourceOrderUuids'][] = $link['sourceOrderUuid'];
                }
            }
        }
        if ($orderedAmount > 9007199254740991 || $openAmount > 9007199254740991) throw new HttpException(409, 'counter_amount_limit');
        $state = ['counter' => ['uuid' => $counter->getUuid(), 'code' => $counter->getCode(), 'name' => $counter->getName(), 'enabled' => $counter->isEnabled(), 'capacity' => $counter->getCapacity()],
            'session' => $session === null ? null : ['uuid' => $session->getUuid(), 'participantCount' => $session->getParticipantCount(), 'notes' => $session->getNotes(), 'startedAt' => $session->getStartedAt()->format(DATE_ATOM), 'revision' => $session->getRevision(),
                'diningModeUuid' => $session->getDiningModeUuid(), 'diningMode' => $session->getDiningModeSnapshot()],
            'orders' => $rows, 'openAmount' => $openAmount, 'orderedAmount' => $orderedAmount, 'openOrderCount' => $openOrders,
            'currency' => $store->getCurrency(), 'paymentHold' => $paymentHold, 'canEnd' => $session !== null && $openOrders === 0];
        $state['version'] = StaffCommandService::version($state);
        return $state;
    }

    /** The canonical version is computed before redaction so every actor sees the same state token.
     * @param array<string, mixed> $view
     * @return array<string, mixed> */
    public function redact(array $view): array
    {
        unset($view['orders'], $view['openAmount'], $view['orderedAmount'], $view['openOrderCount'], $view['paymentHold']);
        $view['ordersVisible'] = false;
        return $view;
    }

    private function canReplace(Order $order, OrderStoreLifecycle $lifecycle, ?StoreOrder $storeOrder): bool
    {
        // Checkout consolidates source orders into one target before applying quantity
        // reductions with replacement, so a merge target is a valid replacement source.
        if (!$this->canChange($order, $lifecycle, $storeOrder, true)) return false;
        // Never silently omit legacy/non-catalog lines while prefilling the replacement cart.
        if ($order->getItems()->isEmpty() || $order->getItems()->count() > 30) return false;
        $seen = [];
        foreach ($order->getItems() as $item) {
            $uuid = $item->getSpecificationUuid();
            if ($uuid === null || !UUID::is_valid($uuid) || isset($seen[$uuid]) || $item->getQuantity() < 1 || $item->getQuantity() > 99) return false;
            $seen[$uuid] = true;
        }
        return true;
    }

    private function canCancel(Order $order, OrderStoreLifecycle $lifecycle, ?StoreOrder $storeOrder): bool
    {
        return $this->canChange($order, $lifecycle, $storeOrder);
    }

    /** Shared eligibility for changing or cancelling an unprocessed, unpaid session order. */
    private function canChange(Order $order, OrderStoreLifecycle $lifecycle, ?StoreOrder $storeOrder, bool $allowMergeTarget = false): bool
    {
        return $this->changeBlockedReason($order, $lifecycle, $storeOrder, $allowMergeTarget) === null;
    }

    private function changeBlockedReason(Order $order, OrderStoreLifecycle $lifecycle, ?StoreOrder $storeOrder, bool $allowMergeTarget = false): ?string
    {
        if ($order->getInvoiceId() !== null || $order->getPaymentStatus() !== null) return 'payment_started';
        if ($storeOrder === null) return 'projection_pending';
        if ($lifecycle->getAcceptanceStatus() !== OrderStoreLifecycle::ACCEPTANCE_PENDING
            || $lifecycle->getFulfillmentStatus() !== OrderStoreLifecycle::FULFILLMENT_PENDING
            || $lifecycle->getVerificationStatus() === OrderStoreLifecycle::VERIFICATION_VERIFIED
            || $storeOrder->getOperationalStatus() !== StoreOrder::STATUS_PENDING_VALIDATION) return 'order_processed';
        if ($storeOrder->getReservationId() !== null
            || $this->em->getRepository(Reservation::class)->count(['tradeOrderUuid' => $order->getUuid()]) !== 0) return 'inventory_reserved';
        // Eligibility is based on trusted ownership and processing state, not who placed the order.
        if (!in_array($order->getStatus(), [Order::STATUS_PENDING, Order::STATUS_CONFIRMED], true)
            || $this->em->getRepository(OrderCounter::class)->count(['tradeOrderUuid' => $order->getUuid()]) !== 1
            || $this->em->getRepository(OrderMerge::class)->count(['sourceOrderUuid' => $order->getUuid()]) !== 0
            || (!$allowMergeTarget && $this->em->getRepository(OrderMerge::class)->count(['targetOrderUuid' => $order->getUuid()]) !== 0)) return 'order_ineligible';
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $order->getUuid(), 'topic' => 'trade.order.created.v1']);
        $snapshot = $event?->getPayload()['store'] ?? null;
        $valid = is_array($snapshot) && ($snapshot['uuid'] ?? null) === $lifecycle->getStoreUuid()
            && ($order->getMetadata()['_store']['uuid'] ?? null) === $lifecycle->getStoreUuid()
            && ($snapshot['requireInventory'] ?? null) === false
            && $this->workflow->can($order, 'cancel');
        return $valid ? null : 'order_ineligible';
    }

    /** @param array<string, mixed> $view */
    private function replacement(array $view, mixed $uuid): Order
    {
        if (!is_string($uuid) || !UUID::is_valid($uuid)) throw new HttpException(422, 'invalid_staff_order_replacement');
        foreach ($view['orders'] as $row) {
            if ($row['uuid'] !== $uuid || !$row['canReplace']) continue;
            $order = $this->findCurrent(Order::class, ['uuid' => $uuid], true);
            if ($order instanceof Order) return $order;
        }
        throw new HttpException(409, 'staff_order_locked');
    }

    /** @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function session(Store $store, string $counterUuid, string $action, array $data): array
    {
        if (!in_array($action, ['open', 'update', 'end'], true)) throw new HttpException(422, 'invalid_counter_session_action');
        $this->fields($data, ['commandUuid', 'version', ...($action === 'update' ? ['participantCount', 'notes'] : [])]);
        $counter = $this->counter($store, $counterUuid, true);
        $before = $this->view($counter, true);
        StaffCommandService::expectVersion($data, $before['version']);
        if ($action === 'open') {
            if (!$counter->isEnabled()) throw new HttpException(409, 'counter_disabled');
            $session = $this->sessions->open($counter);
            return ['before' => ['version' => $before['version'], 'session' => $before['session']],
                'result' => ['counterUuid' => $counterUuid, 'sessionUuid' => $session->getUuid()]];
        }
        $session = $this->sessions->activeSession($counter);
        if ($session === null) throw new HttpException(409, 'counter_session_changed');
        if ($action === 'end') {
            if (!$before['canEnd']) throw new HttpException(409, 'counter_session_not_closed');
            $this->sessions->end($counter);
        } else {
            if (!is_int($data['participantCount'] ?? null) || !is_string($data['notes'] ?? null)) throw new HttpException(422, 'invalid_counter_session_party');
            $session->updateSession($data['participantCount'], $data['notes']);
        }
        return ['before' => ['version' => $before['version'], 'session' => $before['session']], 'result' => ['counterUuid' => $counterUuid, 'sessionUuid' => $session->getUuid()]];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function quote(Store $store, Counter $counter, array $data): array
    {
        return $this->prepareQuote($store, $counter, $data)['quote'];
    }

    /** @param array<string, mixed> $data
     * @return array{quote: array<string, mixed>, calculation: PriceCalculationResult, original: Order|null, before: array<string, mixed>, diningMode: array<string, mixed>|null} */
    private function prepareQuote(Store $store, Counter $counter, array $data): array
    {
        $this->fields($data, ['version', 'items', 'notes', 'diningModeUuid', 'metadata', 'replaceOrderUuid']);
        $metadata = $this->staffMetadata($data);
        $view = $this->view($counter, true);
        $this->assertOrdering($counter, $view, $data);
        $session = $this->sessions->activeSession($counter);
        $diningMode = $this->diningModes->forOrder($store, $session, $data);
        $original = array_key_exists('replaceOrderUuid', $data) ? $this->replacement($view, $data['replaceOrderUuid']) : null;
        if (!is_string($data['notes'] ?? null) || mb_strlen($data['notes']) > 1000) throw new HttpException(422, 'invalid_staff_order_notes');
        $result = $this->pricing->calculate($store, $data['items'] ?? null, $original?->getUser());
        $items = array_map(static fn(array $item): array => ['specificationUuid' => $item['specificationUuid'], 'productName' => $item['productSnapshot']['name'],
            'specificationName' => $item['specificationName'], 'quantity' => $item['quantity'], 'unitPrice' => $item['unitPrice'], 'amount' => $item['price']], $result->items);
        $policy = StoreSettings::from($store->getSettings())->toArray();
        $quote = ['items' => $items, 'totalAmount' => $result->totalAmount, 'currency' => $result->currency,
            'quoteToken' => StaffCommandService::version(['counterUuid' => $counter->getUuid(), 'session' => $view['session'], 'version' => $view['version'],
                'policy' => $policy, 'notes' => trim($data['notes']), 'diningMode' => $diningMode, 'metadata' => $metadata,
                'replacement' => $original === null ? null : ['uuid' => $original->getUuid(), 'customerUuid' => $original->getUser()?->getUuid(), 'metadata' => $original->getMetadata()],
                'items' => $result->items, 'totalAmount' => $result->totalAmount, 'currency' => $result->currency])];
        $before = ['sessionUuid' => $view['session']['uuid'], 'version' => $view['version']];
        if ($original !== null) {
            foreach ($view['orders'] as $row) if ($row['uuid'] === $original->getUuid()) $before['order'] = $row;
        }
        return ['quote' => $quote, 'calculation' => $result, 'original' => $original, 'before' => $before, 'diningMode' => $diningMode];
    }

    /** @param array<string, mixed> $view
     * @param array<string, mixed> $data */
    private function assertOrdering(Counter $counter, array $view, array $data): void
    {
        StaffCommandService::expectVersion($data, $view['version']);
        if (!$counter->isEnabled() || $view['session'] === null || $view['paymentHold'] || count($view['orders']) >= 200) throw new HttpException(409, 'staff_order_locked');
    }

    /** @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function submit(Store $store, string $counterUuid, array $data, bool $replace = false): array
    {
        $this->fields($data, ['commandUuid', 'version', 'items', 'notes', 'diningModeUuid', 'metadata', 'quoteToken', ...($replace ? ['replaceOrderUuid', 'reason'] : [])]);
        if ($replace && (!is_string($data['reason'] ?? null) || trim($data['reason']) === '' || mb_strlen($data['reason']) > 500)) throw new HttpException(422, 'staff_order_reason_required');
        if ($replace && (!is_string($data['replaceOrderUuid'] ?? null) || !UUID::is_valid($data['replaceOrderUuid']))) throw new HttpException(422, 'invalid_staff_order_replacement');
        $counter = $this->counter($store, $counterUuid, true);
        $quoteInput = array_intersect_key($data, array_flip(['version', 'items', 'notes', 'diningModeUuid', 'metadata', 'replaceOrderUuid']));
        $prepared = $this->prepareQuote($store, $counter, $quoteInput);
        $quote = $prepared['quote'];
        if (!is_string($data['quoteToken'] ?? null) || !hash_equals($quote['quoteToken'], $data['quoteToken'])) throw new HttpException(409, 'staff_order_quote_changed');
        $session = $this->sessions->activeSession($counter);
        if (!$session instanceof CounterSession) throw new HttpException(409, 'counter_session_changed');
        if (is_array($prepared['diningMode'])) $session->bindDiningMode($prepared['diningMode']['uuid'], $prepared['diningMode']);
        $before = $prepared['before'];
        $original = $prepared['original'];
        $calculation = $prepared['calculation'];
        $notes = trim($data['notes']);
        $metadata = array_replace($original?->getMetadata() ?? [], $this->staffMetadata($data));
        unset($metadata['counterUuids']);
        $metadata['counterUuid'] = $counter->getUuid();
        $order = $this->orders->create($store, $calculation, $original?->getUser(), $notes === '' ? null : $notes, $metadata);
        $result = ['counterUuid' => $counterUuid, 'sessionUuid' => $session->getUuid(), 'orderUuid' => $order->getUuid()];
        if ($original !== null) {
            $storeOrder = $this->findCurrent(StoreOrder::class, ['tradeOrderUuid' => $original->getUuid()], true);
            if (!$storeOrder instanceof StoreOrder || !$this->workflow->can($original, 'cancel')) throw new HttpException(409, 'staff_order_locked');
            $this->orders->cancel($original, $storeOrder);
            $before['reason'] = trim($data['reason']);
            $result['replacedOrderUuid'] = $original->getUuid();
        }
        $session->changed();
        return ['before' => $before, 'result' => $result];
    }

    /** @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function cancel(Store $store, string $counterUuid, array $data): array
    {
        $this->fields($data, ['commandUuid', 'version', 'orderUuid', 'reason']);
        if (!is_string($data['reason'] ?? null) || trim($data['reason']) === '' || mb_strlen($data['reason']) > 500) throw new HttpException(422, 'staff_order_cancel_reason_required');
        if (!is_string($data['orderUuid'] ?? null) || !UUID::is_valid($data['orderUuid'])) throw new HttpException(422, 'invalid_staff_order_cancel');
        $counter = $this->counter($store, $counterUuid, true);
        $before = $this->view($counter, true);
        StaffCommandService::expectVersion($data, $before['version']);
        $session = $this->sessions->activeSession($counter);
        if (!$session instanceof CounterSession) throw new HttpException(409, 'counter_session_changed');
        $row = null;
        foreach ($before['orders'] as $candidate) if ($candidate['uuid'] === $data['orderUuid']) { $row = $candidate; break; }
        if ($row === null || !$row['canCancel']) throw new HttpException(409, 'staff_order_locked');
        $order = $this->findCurrent(Order::class, ['uuid' => $row['uuid']], true);
        $storeOrder = $this->findCurrent(StoreOrder::class, ['tradeOrderUuid' => $row['uuid']], true);
        if (!$order instanceof Order || !$storeOrder instanceof StoreOrder) throw new HttpException(409, 'staff_order_locked');
        $this->orders->cancel($order, $storeOrder);
        $session->changed();
        return ['before' => ['version' => $before['version'], 'session' => $before['session'], 'order' => $row, 'reason' => trim($data['reason'])],
            'result' => ['counterUuid' => $counterUuid, 'sessionUuid' => $session->getUuid(), 'orderUuid' => $row['uuid'], 'status' => 'cancelled']];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function mergePreview(Store $store, string $counterUuid, User $actor, array $data): array
    {
        $this->fields($data, ['version', 'user']);
        return $this->prepareMerge($store, $counterUuid, $actor, $data)['quote'];
    }

    /** @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function mergeConfirm(Store $store, string $counterUuid, User $actor, array $data): array
    {
        $this->fields($data, ['commandUuid', 'version', 'user', 'quoteToken']);
        $prepared = $this->prepareMerge($store, $counterUuid, $actor, $data);
        if (!is_string($data['quoteToken'] ?? null) || !hash_equals($prepared['quote']['quoteToken'], $data['quoteToken'])) throw new HttpException(409, 'staff_order_quote_changed');
        $session = $prepared['session'];
        $target = $this->orders->createSettlement($store, $prepared['calculation'], $prepared['owner'], null,
            ['counterUuid' => $counterUuid, 'counterSessionUuid' => $session->getUuid(), 'counterSettlement' => true]);
        foreach ($prepared['sources'] as $source) $this->em->persist(new OrderMerge($source->getUuid(), $target->getUuid()));
        $this->em->flush();
        foreach ($prepared['sources'] as $source) {
            $this->workflow->apply($source, 'merge');
            $source->setMetadata([...($source->getMetadata() ?? []), 'mergedIntoOrderUuid' => $target->getUuid()]);
            $projection = $this->findCurrent(StoreOrder::class, ['tradeOrderUuid' => $source->getUuid()], true);
            if (!$projection instanceof StoreOrder) throw new HttpException(409, 'staff_order_locked');
            $this->storeOrders->cancel($projection);
        }
        $this->em->flush();
        if ($this->workflow->can($target, 'confirm')) $this->workflow->apply($target, 'confirm');
        $session->changed();
        return ['before' => ['version' => $prepared['version'], 'sessionUuid' => $session->getUuid(), 'sourceOrderUuids' => $prepared['quote']['sourceOrderUuids'], 'targetUserUuid' => $prepared['owner']->getUuid()],
            'result' => ['counterUuid' => $counterUuid, 'sessionUuid' => $session->getUuid(), 'orderUuid' => $target->getUuid()]];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function prepareMerge(Store $store, string $counterUuid, User $actor, array $data): array
    {
        $owner = $actor;
        if (array_key_exists('user', $data)) {
            if (!is_string($data['user']) || !UUID::is_valid($data['user'])) throw new HttpException(422, 'invalid_target_user');
            $owner = $this->em->getRepository(User::class)->findOneBy(['uuid' => $data['user']]);
            if (!$owner instanceof User) throw new HttpException(422, 'invalid_target_user');
        }
        $counter = $this->counter($store, $counterUuid, true);
        $view = $this->view($counter, true);
        StaffCommandService::expectVersion($data, $view['version']);
        $session = $this->sessions->activeSession($counter);
        if (!$counter->isEnabled() || !$session instanceof CounterSession || $view['paymentHold'] || count($view['orders']) >= 200) throw new HttpException(409, 'staff_order_locked');
        $sources = []; $quantities = []; $sourceTotal = 0;
        foreach ($view['orders'] as $row) {
            if (in_array($row['status'], self::CLOSED, true)) continue;
            $order = $this->findCurrent(Order::class, ['uuid' => $row['uuid']], true);
            $lifecycle = $this->findCurrent(OrderStoreLifecycle::class, ['tradeOrderUuid' => $row['uuid'], 'storeUuid' => $store->getUuid()], true);
            $projection = $this->findCurrent(StoreOrder::class, ['tradeOrderUuid' => $row['uuid']], true);
            $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $row['uuid'], 'topic' => 'trade.order.created.v1']);
            $snapshot = $event?->getPayload()['store'] ?? null;
            if (!$order instanceof Order || !$lifecycle instanceof OrderStoreLifecycle || !$projection instanceof StoreOrder
                || $order->getStatus() !== Order::STATUS_CONFIRMED || $order->getInvoiceId() !== null || $order->getPaymentStatus() !== null
                || !in_array($lifecycle->getAcceptanceStatus(), [OrderStoreLifecycle::ACCEPTANCE_PENDING, OrderStoreLifecycle::ACCEPTANCE_ACCEPTED], true)
                || $lifecycle->getFulfillmentStatus() !== OrderStoreLifecycle::FULFILLMENT_PENDING || !in_array($lifecycle->getVerificationStatus(), [OrderStoreLifecycle::VERIFICATION_PENDING, OrderStoreLifecycle::VERIFICATION_NOT_REQUIRED], true)
                || !in_array($projection->getOperationalStatus(), [StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_ACCEPTED], true)
                || $projection->getReservationId() !== null || $this->em->getRepository(Reservation::class)->count(['tradeOrderUuid' => $row['uuid']]) !== 0
                || $this->em->getRepository(OrderCounter::class)->count(['tradeOrderUuid' => $row['uuid']]) !== 1
                || $this->em->getRepository(OrderMerge::class)->count(['sourceOrderUuid' => $row['uuid']]) !== 0
                || !is_array($snapshot) || ($snapshot['uuid'] ?? null) !== $store->getUuid() || ($snapshot['requireInventory'] ?? null) !== false
                || ($order->getMetadata()['_store']['uuid'] ?? null) !== $store->getUuid()) throw new HttpException(409, 'staff_order_locked');
            foreach ($order->getItems() as $item) {
                $spec = $item->getSpecificationUuid();
                if ($spec === null || !UUID::is_valid($spec) || $item->getQuantity() < 1) throw new HttpException(409, 'staff_order_locked');
                $quantities[$spec] = ($quantities[$spec] ?? 0) + $item->getQuantity();
            }
            $sources[] = $order;
            $sourceTotal += $order->getTotalAmount();
        }
        if (count($sources) < 2 || $quantities === []) throw new HttpException(409, 'staff_order_locked');
        ksort($quantities);
        $items = [];
        foreach ($quantities as $spec => $quantity) $items[] = ['specificationUuid' => $spec, 'quantity' => $quantity];
        $calculation = $this->pricing->calculate($store, $items, $owner);
        $sourceUuids = array_map(static fn(Order $order): string => $order->getUuid(), $sources);
        $displayItems = array_map(static fn(array $item): array => ['specificationUuid' => $item['specificationUuid'], 'productName' => $item['productSnapshot']['name'],
            'specificationName' => $item['specificationName'], 'quantity' => $item['quantity'], 'unitPrice' => $item['unitPrice'], 'amount' => $item['price']], $calculation->items);
        $quote = ['sourceOrderUuids' => $sourceUuids, 'targetUserUuid' => $owner->getUuid(), 'items' => $displayItems,
            'sourceTotalAmount' => $sourceTotal, 'totalAmount' => $calculation->totalAmount,
            'differenceAmount' => $calculation->totalAmount - $sourceTotal, 'currency' => $calculation->currency];
        $quote['quoteToken'] = StaffCommandService::version(['counterUuid' => $counterUuid, 'session' => $view['session'], 'version' => $view['version'],
            'policy' => StoreSettings::from($store->getSettings())->toArray(), 'quote' => $quote, 'calculation' => $calculation->items]);
        return ['quote' => $quote, 'sources' => $sources, 'session' => $session, 'owner' => $owner, 'calculation' => $calculation, 'version' => $view['version']];
    }

    /** @param array<string, mixed> $data
     * @param list<string> $allowed */
    private function fields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) throw new HttpException(422, 'unknown_field');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function staffMetadata(array $data): array
    {
        if (!array_key_exists('metadata', $data)) return [];
        $metadata = $data['metadata'];
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) throw new HttpException(422, 'invalid_staff_order_metadata');
        foreach ($metadata as $key => $_) {
            if (!is_string($key) || $key === '' || str_starts_with($key, '_') || in_array($key, [
                'counterUuid', 'counterUuids', 'counterSessionUuid', 'counterSettlement', 'mergedIntoOrderUuid',
            ], true)) throw new HttpException(422, 'invalid_staff_order_metadata');
        }
        return $metadata;
    }
}
