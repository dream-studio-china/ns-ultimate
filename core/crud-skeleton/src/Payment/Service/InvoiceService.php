<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Core\Service\BaseService;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Exception\InvoiceInvalidTransitionException;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/** @extends BaseService<\App\Payment\Entity\Invoice> */
final class InvoiceService extends BaseService implements InvoiceServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentAdjustmentRegistry $adjustmentRegistry,
        #[Target('state_machine.invoice')]
        private readonly WorkflowInterface $workflow,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        parent::__construct($container, Invoice::class);
    }

    public function createInvoice(CreateInvoiceRequest $request): Invoice
    {
        if ($request->amount < 0) {
            throw new \InvalidArgumentException('Invoice amount cannot be negative.');
        }

        return $this->wrapInTransaction(function () use ($request) {
            $invoice = new Invoice();
            $invoice->setSourceType($request->sourceType);
            $invoice->setSourceId($request->sourceId);
            $invoice->setScene($request->scene);
            $invoice->setAmount($request->amount);
            $invoice->setCurrency($request->currency);
            $invoice->setPayer($request->payer);
            $invoice->setSubject($request->subject);
            $invoice->setDescription($request->description);
            $invoice->setExtraData($request->extraData ?: null);

            $this->getEntityManager()->persist($invoice);

            return $invoice;
        });
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult
    {
        return $this->wrapInTransaction(function () use ($invoice, $payment, $options) {
            if (!$this->workflow->can($invoice, 'start_pay')) {
                throw new InvoiceInvalidTransitionException($invoice, 'start_pay');
            }

            $adjustments = $this->adjustmentRegistry->apply($invoice, $payment, $options);
            $adjustmentAmount = self::sumAdjustments($adjustments);
            $gatewayAmount = $invoice->getAmount() - $adjustmentAmount;

            if ($gatewayAmount < 0) {
                throw new InvoiceAmountMismatchException('Deduction amount exceeds invoice amount.');
            }

            $effectivePayment = $gatewayAmount === 0 ? Invoice::PAYMENT_WALLET : $payment;
            $invoice->setPayment($effectivePayment);
            if (isset($options['gateway'])) {
                $invoice->setGateway((string) $options['gateway']);
            }
            if (isset($options['tradeType'])) {
                $invoice->setTradeType((string) $options['tradeType']);
            }
            $this->workflow->apply($invoice, 'start_pay');
            $this->getEntityManager()->flush();

            if ($gatewayAmount === 0) {
                $payload = [
                    'adjustmentOnly' => true,
                    'gatewayAmount' => 0,
                    'adjustments' => self::serializeAdjustments($adjustments),
                ];
                if (($firstPayload = $adjustments[0]->payload ?? []) !== []) {
                    $payload += $firstPayload;
                }
                $this->markPaid($invoice, new PaymentNotifyResult(
                    payment: Invoice::PAYMENT_WALLET,
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: 0,
                    currency: $invoice->getCurrency(),
                    transactionId: $payload['transactionId'] ?? null,
                    paidAt: new \DateTimeImmutable(),
                    rawData: $payload,
                ));

                return new PaymentResult(
                    invoice: $invoice,
                    status: Invoice::STATUS_PAID,
                    payload: $payload,
                    message: 'Wallet deduction payment completed',
                );
            }

            try {
                $gateway = $this->gatewayRegistry->get($payment);
                $result = $gateway->pay($invoice, $gatewayAmount, $options);
            } catch (\Throwable $e) {
                if ($adjustments !== []) {
                    $this->adjustmentRegistry->releaseApplied($invoice, 'Gateway payment failed: ' . $e->getMessage());
                }
                throw $e;
            }
            $payload = $result->payload ?? [];
            if ($payload) {
                $invoice->appendExtraData('pay', $this->sanitizePayload($payload));
            }

            if ($result->status === Invoice::STATUS_PAID) {
                $this->markPaid($invoice, new PaymentNotifyResult(
                    payment: $payment,
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: $gatewayAmount,
                    currency: $invoice->getCurrency(),
                    transactionId: $payload['transactionId'] ?? null,
                    paidAt: new \DateTimeImmutable(),
                    rawData: $payload,
                ));
            }

            return $result;
        });
    }

    public function handleNotifyResult(PaymentNotifyResult $result): Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = $this->getRepository()->findOneBy(['outTradeNo' => $result->outTradeNo]);
        if (!$invoice) {
            throw new \RuntimeException(sprintf('Invoice %s not found.', $result->outTradeNo));
        }

        return match ($result->status) {
            Invoice::STATUS_PAID => $this->markPaid($invoice, $result),
            Invoice::STATUS_FAILED => $this->markFailed($invoice, $result),
            default => throw new \InvalidArgumentException(sprintf('Unsupported notify status "%s".', $result->status)),
        };
    }

    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return $invoice;
        }
        if ($invoice->getStatus() === Invoice::STATUS_CANCELLED) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }
        $expectedAmount = $invoice->getAmount() - $this->adjustmentRegistry->sumAppliedAmount($invoice);
        if ($expectedAmount !== $result->amount || $invoice->getCurrency() !== strtoupper($result->currency)) {
            throw new InvoiceAmountMismatchException('Payment notify amount or currency does not match invoice.');
        }
        if (!$this->workflow->can($invoice, 'mark_paid')) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }

        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $invoice->setPayment($result->payment);
            $invoice->setTransactionId($result->transactionId);
            $invoice->setPaidAt($result->paidAt ?? new \DateTimeImmutable());
            $invoice->appendExtraData('notify', $this->sanitizePayload($result->rawData));
            $this->workflow->apply($invoice, 'mark_paid');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoicePaidEvent($invoice, $result));

            return $invoice;
        });
    }

    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return $invoice;
        }
        if (!$this->workflow->can($invoice, 'fail')) {
            throw new InvoiceInvalidTransitionException($invoice, 'fail');
        }

        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $this->adjustmentRegistry->releaseApplied($invoice, 'Invoice payment failed.');
            $invoice->appendExtraData('notify_failed', $this->sanitizePayload($result->rawData));
            $this->workflow->apply($invoice, 'fail');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceFailedEvent($invoice, $result));

            return $invoice;
        });
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (!$this->workflow->can($invoice, 'cancel')) {
            throw new InvoiceInvalidTransitionException($invoice, 'cancel');
        }

        return $this->wrapInTransaction(function () use ($invoice, $reason) {
            $this->adjustmentRegistry->releaseApplied($invoice, $reason ?? 'Invoice cancelled.');
            if ($reason !== null) {
                $invoice->appendExtraData('cancel', ['reason' => $reason]);
            }
            $invoice->setCancelledAt(new \DateTimeImmutable());
            $this->workflow->apply($invoice, 'cancel');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceCancelledEvent($invoice));

            return $invoice;
        });
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        $hasAdjustment = $this->adjustmentRegistry->hasApplied($invoice);
        if ($hasAdjustment && $amount !== $invoice->getAmount()) {
            throw new InvoiceAmountMismatchException('Adjusted invoices only support full refund.');
        }

        $remaining = $invoice->getAmount() - $invoice->getRefundedAmount();
        if ($amount > $remaining) {
            throw new InvoiceAmountMismatchException('Refund amount exceeds paid remaining amount.');
        }
        if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)) {
            throw new InvoiceInvalidTransitionException($invoice, 'refund');
        }

        $payment = $invoice->getPayment() ?? throw new \RuntimeException('Invoice has no payment gateway.');

        return $this->wrapInTransaction(function () use ($invoice, $amount, $reason, $options, $payment, $hasAdjustment) {
            $adjustmentAmount = $this->adjustmentRegistry->sumAppliedAmount($invoice);
            $gatewayAmount = $hasAdjustment ? $amount - $adjustmentAmount : $amount;
            $rawData = [];
            $refundId = null;

            if ($gatewayAmount > 0) {
                $gateway = $this->gatewayRegistry->get($payment);
                $gatewayPaidAmount = $invoice->getAmount() - $adjustmentAmount;
                $result = $gateway->refund($invoice, $gatewayAmount, $gatewayPaidAmount, $reason, $options);
                $refundId = $result->refundId;
                $rawData['gateway'] = $result->rawData;
            }

            if ($hasAdjustment) {
                $rawData['adjustments'] = self::serializeAdjustments($this->adjustmentRegistry->refundApplied($invoice, $reason));
            }

            $newRefundedAmount = $invoice->getRefundedAmount() + $amount;
            $invoice->setRefundedAmount($newRefundedAmount);
            $invoice->appendExtraData('refund_' . ($refundId ?? count($invoice->getExtraData() ?? [])), $this->sanitizePayload($rawData));

            $transition = $newRefundedAmount >= $invoice->getAmount() ? 'refund' : 'partial_refund';
            if (!$this->workflow->can($invoice, $transition)) {
                throw new InvoiceInvalidTransitionException($invoice, $transition);
            }
            if ($transition === 'refund') {
                $invoice->setRefundedAt(new \DateTimeImmutable());
            }
            $this->workflow->apply($invoice, $transition);
            $this->getEntityManager()->flush();

            $finalResult = new PaymentRefundResult($invoice, $amount, $invoice->getStatus(), $refundId, $rawData);
            $this->dispatcher->dispatch(new InvoiceRefundedEvent($invoice, $finalResult));

            return $finalResult;
        });
    }

    /** @return list<Invoice> */
    public function findBySource(string $sourceType, string $sourceId): array
    {
        /** @var list<Invoice> */
        return $this->getRepository()->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId], ['id' => 'DESC']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['password', 'secret', 'token', 'privateKey', 'signature'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }
        return $payload;
    }

    /** @param PaymentAdjustmentResult[] $adjustments */
    private static function sumAdjustments(array $adjustments): int
    {
        $sum = 0;
        foreach ($adjustments as $adjustment) {
            $sum += $adjustment->amount;
        }

        return $sum;
    }

    /** @param PaymentAdjustmentResult[] $adjustments
     * @return mixed[][] */
    private static function serializeAdjustments(array $adjustments): array
    {
        return array_map(static fn (PaymentAdjustmentResult $adjustment): array => [
            'provider' => $adjustment->provider,
            'amount' => $adjustment->amount,
            'currency' => $adjustment->currency,
            'referenceId' => $adjustment->referenceId,
            'payload' => $adjustment->payload,
        ], $adjustments);
    }
}
