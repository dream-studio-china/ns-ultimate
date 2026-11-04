<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerHuifuPaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerPaymentPolicy $policy,
        private readonly HuifuPaymentService $payments,
    ) {}

    /** @param callable(CustomerPaymentContext): PaymentResult $prepare */
    public function start(Order $order, User $payer, string $channel, ?string $tradeType, ?string $clientIp, callable $prepare): PaymentResult
    {
        if ($this->em->getConnection()->isTransactionActive()) throw new \LogicException('Customer Huifu payment requires a committed preparation boundary.');
        $context = $this->policy->authorize($order, $payer);
        $channel = $this->policy->channel($context, $channel, $tradeType);
        if ($order->getInvoiceId() !== null) {
            $linked = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
            if (!$linked instanceof Invoice || $linked->getSourceType() !== 'trade_order' || $linked->getSourceId() !== $order->getUuid()
                || $linked->getScene() !== Invoice::SCENE_ORDER || $linked->getAmount() !== $order->getTotalAmount()
                || $linked->getCurrency() !== $order->getCurrency() || in_array($linked->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED, Invoice::STATUS_REFUNDED], true)) {
                throw new HttpException(409, 'order_payment_conflict');
            }
        }
        try {
            $prepared = $prepare($context);
        } catch (\Throwable $error) {
            throw new HttpException(409, 'order_payment_unavailable', $error);
        }
        $invoice = $prepared->invoice;
        try {
            return $this->payments->start($invoice, $channel, [
                'tradeType' => $channel === 'native' ? 'T_NATIVE' : 'T_JSAPI', 'clientIp' => $clientIp,
                '_customerOrder' => $context,
            ]);
        } catch (\Throwable $error) {
            // Core may have rolled back its Invoice transition and the Huifu
            // recovery path may have cleared Doctrine's identity map.
            $currentInvoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $invoice->getUuid()]);
            if ($currentInvoice instanceof Invoice) {
                $this->em->refresh($currentInvoice);
                $invoice = $currentInvoice;
            }
            $attempt = $this->em->getRepository(HuifuPaymentAttempt::class)->findOneBy(['invoice' => $invoice], ['id' => 'DESC']);
            if ($attempt instanceof HuifuPaymentAttempt && in_array($attempt->getStatus(), [HuifuPaymentAttempt::STATUS_UNKNOWN, HuifuPaymentAttempt::STATUS_PENDING, HuifuPaymentAttempt::STATUS_SUBMITTED], true)) {
                return new PaymentResult($invoice, 'unknown', payload: ['attemptUuid' => $attempt->getUuid(),
                    'reqSeqId' => $attempt->getReqSeqId(), 'reqDate' => $attempt->getReqDate(), 'channel' => $attempt->getChannel(), 'tradeType' => $attempt->getTradeType()],
                    message: 'Payment requires reconciliation; do not automatically retry.');
            }
            if ($error instanceof HttpException) throw $error;
            throw new HttpException(502, 'huifu_payment_unavailable', $error);
        }
    }
}
