<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderService;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Inventory\Service\OrderInventoryAvailability;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Serialize payment with Counter merges without changing the upstream Trade service. */
#[AsDecorator(decorates: OrderService::class)]
final class CounterTradeOrderService implements OrderServiceInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly OrderServiceInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly OrderInventoryAvailability $inventory,
    ) {}

    public function createPayment(Order $order, string $payment = 'mock', array $options = []): PaymentResult
    {
        return $this->inner->wrapInTransaction(function () use ($order, $payment, $options): PaymentResult {
            // Refresh under the same order-row lock used by merge. Checking the caller's
            // potentially stale entity before acquiring the lock is not sufficient.
            $lock = $this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform
                ? LockMode::NONE : LockMode::PESSIMISTIC_WRITE;
            $this->em->refresh($order, $lock);
            if ($this->em->getRepository(OrderMerge::class)->findOneBy(['sourceOrderUuid' => $order->getUuid()]) !== null) {
                throw new \DomainException('Merged source orders cannot start payment.');
            }
            if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
                throw new \DomainException('Only confirmed orders can start payment.');
            }
            if (!$this->inventory->hasValidInventory($order)) {
                throw new \DomainException('A valid inventory reservation is required before payment.');
            }

            return $this->inner->createPayment($order, $payment, $options);
        });
    }

    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
    {
        return $this->inner->calculatePrices($items, $currency, $storeCode, $meta);
    }

    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order
    {
        return $this->inner->createOrder($calculatedItems, $user, $totalAmount, $currency, $notes, $metadata, $storeContext);
    }

    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void
    {
        $this->inner->refund($order, $systemWalletId, $reason, $referenceId);
    }

    public function fulfill(Order $order, array $data): void { $this->inner->fulfill($order, $data); }
    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult { return $this->inner->refundPayment($order, $reason, $options); }
    public function cancel(Order $order): void { $this->inner->cancel($order); }
    public function wrapInTransaction(callable $fn): mixed { return $this->inner->wrapInTransaction($fn); }
    public function get($object, bool $directly = false) { return $this->inner->get($object, $directly); }
    public function list($object = null, $order = null, bool $disableRequest = true) { return $this->inner->list($object, $order, $disableRequest); }
    public function new() { return $this->inner->new(); }
    public function update($object, ?array $data = null, bool $noFlush = false) { return $this->inner->update($object, $data, $noFlush); }
    public function remove($object): bool { return $this->inner->remove($object); }
}
