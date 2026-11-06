<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Business\Payment\Service\PaymentCloseGatewayRegistry;
use NsUltimate\Business\Store\Entity\StaffOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit channel-confirmed payment closure, not cancellation of the order. */
final class OrderPaymentCloseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentCloseGatewayRegistry $gateways,
        private readonly StaffCommandService $commands,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array{commandUuid: string, invoiceUuid: string, reason: string} $data
     * @return array<string, mixed> */
    public function close(Store $store, string $orderUuid, string $actorUuid, array $data): array
    {
        if ($this->em->getConnection()->isTransactionActive()) throw new \LogicException('Payment closure requires an independent transaction.');
        return $this->commands->run($store, $actorUuid, 'order.payment_close.'.$orderUuid, $data, function () use ($store, $orderUuid, $data): array {
            $order = $this->em->getRepository(Order::class)->findByUuidForUpdate($orderUuid);
            $scope = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $orderUuid, 'storeUuid' => $store->getUuid()]);
            if (!$order instanceof Order || !$scope instanceof OrderStoreLifecycle) throw new HttpException(404, 'order_not_found');
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getStatus() !== Order::STATUS_CONFIRMED) throw new HttpException(409, 'order_not_payable');
            if ($order->getInvoiceId() !== $data['invoiceUuid']) throw new HttpException(409, 'order_invoice_changed');

            // Refuse ambiguous historical collections. Releasing the current reference
            // must not hide another payable or already-paid Invoice for this source.
            $rows = $this->em->createQueryBuilder()->select('i')->from(Invoice::class, 'i')
                ->where('i.sourceType = :type AND i.sourceId = :source')->setParameter('type', 'trade_order')->setParameter('source', $orderUuid)
                ->orderBy('i.id', 'ASC')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
            $confirmedClosures = [];
            if (count($rows) > 1) {
                foreach ($this->em->getRepository(StaffOperation::class)->findBy(['storeUuid' => $store->getUuid(), 'action' => 'order.payment_close.'.$orderUuid]) as $receipt) {
                    $result = $receipt->getResult();
                    if (($result['released'] ?? null) === true && ($result['status'] ?? null) === 'closed' && is_string($result['invoiceUuid'] ?? null)) $confirmedClosures[] = $result['invoiceUuid'];
                }
            }
            $invoice = null;
            foreach ($rows as $candidate) {
                if ($candidate->getUuid() === $data['invoiceUuid']) {
                    $invoice = $candidate;
                } elseif ($candidate->getStatus() !== Invoice::STATUS_CANCELLED || !in_array($candidate->getUuid(), $confirmedClosures, true)) {
                    throw new HttpException(409, 'multiple_payment_invoices');
                }
            }
            if (!$invoice instanceof Invoice || $invoice->getAmount() !== $order->getTotalAmount()
                || $invoice->getCurrency() !== $order->getCurrency()) throw new HttpException(409, 'order_invoice_mismatch');
            if (!in_array($invoice->getStatus(), [Invoice::STATUS_PENDING, Invoice::STATUS_PAYING, Invoice::STATUS_FAILED, Invoice::STATUS_CANCELLED], true)
                || $invoice->getPaidAt() !== null || $invoice->getRefundedAmount() !== 0 || $order->getPaidAt() !== null) throw new HttpException(409, 'payment_already_settled');
            $before = ['tradeOrderUuid' => $orderUuid, 'invoiceUuid' => $invoice->getUuid(), 'invoiceStatus' => $invoice->getStatus(), 'reason' => $data['reason']];

            // Hold the source and Invoice locks through the bounded channel call.
            // This serializes with existing payment/merge and Invoice callback locks.
            $result = $this->gateways->get($invoice->getPayment() ?? '')->close($invoice);
            if ($result->payment !== null) {
                $payment = $result->payment;
                if ($payment->status !== Invoice::STATUS_PAID || $payment->payment !== $invoice->getPayment()
                    || $payment->outTradeNo !== $invoice->getOutTradeNo() || $payment->amount !== $invoice->getAmount()
                    || $payment->currency !== $invoice->getCurrency()) throw new HttpException(409, 'payment_confirmation_mismatch');
                $requiresReview = !in_array($invoice->getStatus(), [Invoice::STATUS_PENDING, Invoice::STATUS_PAYING], true);
                if ($requiresReview) {
                    // Core cannot mark a failed/cancelled Invoice paid. Preserve the
                    // verified collection for review without inventing a transition.
                    $invoice->appendExtraData('payment_close_collection', ['transactionId' => $payment->transactionId, 'amount' => $payment->amount, 'currency' => $payment->currency]);
                    $this->logger->critical('Payment closure discovered collection on a terminal Invoice.', ['tradeOrderUuid' => $orderUuid, 'invoiceUuid' => $invoice->getUuid()]);
                } else {
                    $this->invoices->handleNotifyResult($payment);
                }
                return ['before' => $before, 'result' => ['status' => 'paid', 'tradeOrderUuid' => $orderUuid, 'invoiceUuid' => $invoice->getUuid(), 'invoiceStatus' => $invoice->getStatus(), 'released' => false, 'requiresReview' => $requiresReview]];
            }
            if (!$result->closed) throw new HttpException(409, 'payment_close_unconfirmed');
            if ($invoice->getStatus() !== Invoice::STATUS_CANCELLED) $this->invoices->cancel($invoice, $data['reason']);
            $order->setInvoiceId(null)->setInvoiceNo(null)->setPaymentStatus(null)->setPaymentMethod(null);
            $this->em->flush();
            return ['before' => $before, 'result' => ['status' => 'closed', 'tradeOrderUuid' => $orderUuid, 'invoiceUuid' => $invoice->getUuid(), 'invoiceStatus' => $invoice->getStatus(), 'released' => true]];
        });
    }
}
