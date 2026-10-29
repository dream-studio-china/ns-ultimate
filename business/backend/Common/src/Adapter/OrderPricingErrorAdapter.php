<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Adapter;

use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\OrderService;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Translate unavailable catalog items into a client error.
 *
 * The core pricing service throws SpecificationNotFoundException, which the
 * shared CRUD mixins only map to HTTP 500. Admin and app order endpoints expect
 * an invalid selection to be a 400, so this adapter keeps that guarantee in the
 * business layer without editing the core.
 */
#[AsDecorator(decorates: OrderService::class)]
final class OrderPricingErrorAdapter implements OrderServiceInterface
{
    public function __construct(#[AutowireDecorated] private readonly OrderServiceInterface $inner) {}

    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
    {
        try {
            return $this->inner->calculatePrices($items, $currency, $storeCode, $meta);
        } catch (SpecificationNotFoundException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
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

    public function createPayment(Order $order, string $payment = 'mock', array $options = []): PaymentResult
    {
        return $this->inner->createPayment($order, $payment, $options);
    }

    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult
    {
        return $this->inner->refundPayment($order, $reason, $options);
    }

    public function cancel(Order $order): void { $this->inner->cancel($order); }
    public function wrapInTransaction(callable $fn): mixed { return $this->inner->wrapInTransaction($fn); }
    public function get($object, bool $directly = false) { return $this->inner->get($object, $directly); }
    public function list($object = null, $order = null, bool $disableRequest = true) { return $this->inner->list($object, $order, $disableRequest); }
    public function new() { return $this->inner->new(); }
    public function update($object, ?array $data = null, bool $noFlush = false) { return $this->inner->update($object, $data, $noFlush); }
    public function remove($object): bool { return $this->inner->remove($object); }
}
