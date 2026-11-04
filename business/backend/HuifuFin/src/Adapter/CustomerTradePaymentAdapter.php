<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Adapter;

use App\Identity\Entity\User;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderService;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use NsUltimate\Business\HuifuFin\Service\Payment\CustomerHuifuPaymentService;
use NsUltimate\Business\HuifuFin\Service\Payment\CustomerPaymentContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;

/** Keep the ordinary app payment API while finishing Huifu outside Counter's transaction. */
#[AsDecorator(decorates: OrderService::class, priority: -100)]
final class CustomerTradePaymentAdapter implements OrderServiceInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly OrderServiceInterface $inner,
        private readonly CustomerHuifuPaymentService $payments,
        private readonly RequestStack $requests,
    ) {}

    public function createPayment(Order $order, string $payment = 'mock', array $options = []): PaymentResult
    {
        $payer = $options['_payer'] ?? null;
        if ($payment !== 'huifu' || !$payer instanceof User) return $this->inner->createPayment($order, $payment, $options);
        if (array_diff(array_keys($options), ['payment', 'gateway', 'tradeType', '_payer'])
            || (array_key_exists('gateway', $options) && !is_string($options['gateway']))
            || (array_key_exists('tradeType', $options) && !is_string($options['tradeType']))) {
            throw new \InvalidArgumentException('invalid_huifu_payment_request');
        }
        return $this->payments->start($order, $payer, $options['gateway'] ?? '', $options['tradeType'] ?? null,
            $this->requests->getCurrentRequest()?->getClientIp(),
            fn(CustomerPaymentContext $context): PaymentResult => $this->inner->createPayment($order, $payment, ['_payer' => $payer, '_huifuPreparation' => $context]));
    }

    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult { return $this->inner->calculatePrices($items, $currency, $storeCode, $meta); }
    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order { return $this->inner->createOrder($calculatedItems, $user, $totalAmount, $currency, $notes, $metadata, $storeContext); }
    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void { $this->inner->refund($order, $systemWalletId, $reason, $referenceId); }
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
