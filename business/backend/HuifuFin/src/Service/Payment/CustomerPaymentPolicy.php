<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Common\Entity\Setting;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerPaymentPolicy
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly HuifuProfileResolver $profiles) {}

    public function authorize(Order $order, User $payer): CustomerPaymentContext
    {
        if ($order->getUser()?->getUuid() !== $payer->getUuid()) {
            $setting = $this->em->getRepository(Setting::class)->findOneBy(['key' => 'payment.allowThirdPartyPayment']);
            if (!$setting instanceof Setting || $setting->getType() !== 'boolean' || $setting->getValue() !== 'true') {
                throw new HttpException(404, 'order_not_found');
            }
        }
        if ($order->getStatus() !== Order::STATUS_CONFIRMED) throw new HttpException(409, 'order_not_payable');
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        $store = $lifecycle instanceof OrderStoreLifecycle ? $this->em->getRepository(Store::class)->findOneBy(['uuid' => $lifecycle->getStoreUuid()]) : null;
        if (!$store instanceof Store || !$store->isActive()) throw new HttpException(404, 'order_not_found');
        if ($order->getCurrency() !== 'CNY' || $order->getTotalAmount() <= 0 || $order->getTotalAmount() > 999999999999) {
            throw new HttpException(409, 'order_amount_not_supported');
        }
        return new CustomerPaymentContext($order->getUuid(), $payer->getUuid(), $store->getUuid(), $order->getTotalAmount(), $order->getCurrency());
    }

    public function channel(CustomerPaymentContext $context, string $channel, ?string $tradeType): string
    {
        $configured = $this->profiles->publicChannels($context->storeUuid);
        $channel = $channel !== '' ? $channel : ($configured['defaultChannel'] ?? '');
        if ($channel === '' || !in_array($channel, $configured['channels'], true)) throw new HttpException(409, 'huifu_channel_unavailable');
        $expected = $channel === 'native' ? 'T_NATIVE' : 'T_JSAPI';
        if ($tradeType !== null && $tradeType !== $expected) throw new HttpException(422, 'invalid_huifu_trade_type');
        return $channel;
    }

    /** Must be called in the caller's transaction, before any provider request. */
    public function assertReady(Invoice $invoice, CustomerPaymentContext $context, string $channel): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Customer payment validation requires a transaction.');
        $order = $this->em->getRepository(Order::class)->findByUuidForUpdate($context->orderUuid);
        $payer = $invoice->getPayer();
        if (!$order instanceof Order || !$payer instanceof User || $payer->getUuid() !== $context->payerUuid
            || $invoice->getSourceType() !== 'trade_order' || $invoice->getSourceId() !== $order->getUuid()
            || $invoice->getScene() !== Invoice::SCENE_ORDER || $order->getInvoiceId() !== $invoice->getUuid()
            || $invoice->getAmount() !== $context->amount || $invoice->getCurrency() !== $context->currency) {
            throw new HttpException(409, 'order_payment_changed');
        }
        $current = $this->authorize($order, $payer);
        if ($current->storeUuid !== $context->storeUuid || $current->amount !== $context->amount || $current->currency !== $context->currency) {
            throw new HttpException(409, 'order_payment_changed');
        }
        $this->channel($current, $channel, null);
    }
}
