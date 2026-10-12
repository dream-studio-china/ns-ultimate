<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Payment\DTO\PaymentRefundResult;
use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Project-owned WeChat refund orchestrator (confirmed option A).
 *
 * Refunds are persisted first with a stable provider refund number, and pending
 * amounts are reserved so concurrent requests cannot over-refund. Only a
 * verified provider success updates refunded accounting; processing/unknown
 * results stay pending for query/reconciliation. Core's eager refund accounting
 * path is never used for these refunds.
 *
 * Adjusted (wallet/coupon-combined) invoices are rejected before any provider
 * call until adjusted-refund accounting is implemented.
 */
final class WechatRefundOrchestrator
{
    public function __construct(
        private readonly StoreWechatResolver $resolver,
        private readonly WechatPayProviderInterface $provider,
        private readonly PaymentAdjustmentRegistry $adjustments,
        private readonly WorkflowInterface $invoiceWorkflow,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatAuditLogger $audit,
        private readonly string $refundNotifyUrl,
    ) {
    }

    public function requestRefund(Invoice $invoice, int $amount, string $reason, array $options = []): WechatRefundAttempt
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)) {
            throw new \InvalidArgumentException('Only paid invoices can be refunded.');
        }
        if ($this->adjustments->hasApplied($invoice)) {
            throw new \InvalidArgumentException('Invoices with payment adjustments do not support WeChat refunds yet.');
        }

        $paymentAttempt = $this->entityManager->getRepository(WechatPaymentAttempt::class)
            ->findOneBy(['invoiceUuid' => $invoice->getUuid(), 'status' => WechatPaymentAttempt::STATUS_SUCCEEDED]);
        if (!$paymentAttempt instanceof WechatPaymentAttempt) {
            throw new \InvalidArgumentException('The invoice has no completed store WeChat payment to refund.');
        }

        $idempotencyKey = trim((string) ($options['idempotencyKey'] ?? $invoice->getUuid().':'.$amount));
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('A refund idempotency key is required.');
        }

        $existing = $this->entityManager->getRepository(WechatRefundAttempt::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($existing instanceof WechatRefundAttempt) {
            if ($existing->getAmount() !== $amount || $existing->getPaymentAttempt()?->getId() !== $paymentAttempt->getId()) {
                throw new \InvalidArgumentException('The refund idempotency key was reused with different inputs.');
            }

            return $existing;
        }

        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->lock($paymentAttempt, LockMode::PESSIMISTIC_WRITE);

            $remaining = $invoice->getAmount() - $invoice->getRefundedAmount() - $this->pendingReserved($invoice);
            if ($amount > $remaining) {
                throw new \InvalidArgumentException('Refund amount exceeds the available remaining amount.');
            }

            $refund = (new WechatRefundAttempt())
                ->setPaymentAttempt($paymentAttempt)
                ->setProviderRefundNo($this->generateRefundNo())
                ->setIdempotencyKey($idempotencyKey)
                ->setAmount($amount)
                ->setStatus(WechatRefundAttempt::STATUS_REQUESTED);
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $e;
        }

        $config = $this->resolver->resolveForAttempt($paymentAttempt);

        $body = [
            'out_trade_no' => $paymentAttempt->getProviderOrderNo(),
            'out_refund_no' => $refund->getProviderRefundNo(),
            'reason' => $reason !== '' ? $reason : 'Refund',
            'notify_url' => rtrim($this->refundNotifyUrl, '/').'/'.$refund->getUuid(),
            'amount' => [
                'refund' => $amount,
                'total' => $paymentAttempt->getExpectedAmount(),
                'currency' => $paymentAttempt->getCurrency(),
            ],
        ];
        if ($config->merchantMode === WechatMerchant::MODE_PARTNER) {
            $body['sub_mchid'] = (string) $config->subMerchantId;
        }

        try {
            $created = $this->provider->createRefund($config, $body);
        } catch (\Throwable $e) {
            $refund->setStatus(WechatRefundAttempt::STATUS_UNKNOWN);
            $this->entityManager->flush();
            $this->audit->record('refund-unknown', 'WechatRefundAttempt', $refund->getUuid(), 'failure', $paymentAttempt->getStoreUuid(), [
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return $refund;
        }

        $refund->setProviderRefundId($created['refund_id'] ?? null);
        $status = strtoupper((string) ($created['status'] ?? ''));
        if ($status === 'SUCCESS') {
            $refund->setStatus(WechatRefundAttempt::STATUS_SUCCEEDED);
            $this->applyAccounting($refund, $created['raw'] ?? []);
        } elseif (in_array($status, ['CLOSED', 'ABNORMAL'], true)) {
            $refund->setStatus(WechatRefundAttempt::STATUS_FAILED);
        } else {
            $refund->setStatus(WechatRefundAttempt::STATUS_PROCESSING);
        }
        $this->entityManager->flush();
        $this->audit->record('refund-request', 'WechatRefundAttempt', $refund->getUuid(), 'success', $paymentAttempt->getStoreUuid(), [
            'status' => $refund->getStatus(),
        ]);

        return $refund;
    }

    /**
     * Confirm a refund from a notify payload or provider query result.
     *
     * @param array<string, mixed> $providerData
     * @return string one of succeeded|failed|pending
     */
    public function confirmRefund(WechatRefundAttempt $refund, array $providerData, string $source): string
    {
        $paymentAttempt = $refund->getPaymentAttempt();
        if (!$paymentAttempt instanceof WechatPaymentAttempt) {
            throw new \InvalidArgumentException('The refund has no payment attempt.');
        }

        $config = $this->resolver->resolveForAttempt($paymentAttempt);
        $this->verify($refund, $config, $providerData);

        $status = strtoupper((string) ($providerData['refund_status'] ?? $providerData['status'] ?? ''));
        if ($status === 'SUCCESS') {
            $refund->setProviderRefundId(
                isset($providerData['refund_id']) ? (string) $providerData['refund_id'] : $refund->getProviderRefundId(),
            );
            $refund->setStatus(WechatRefundAttempt::STATUS_SUCCEEDED);
            $this->applyAccounting($refund, $providerData);
            $this->entityManager->flush();
            $this->audit->record('refund-confirm', 'WechatRefundAttempt', $refund->getUuid(), 'success', $paymentAttempt->getStoreUuid(), [
                'source' => $source,
            ]);

            return 'succeeded';
        }

        if (in_array($status, ['CLOSED', 'ABNORMAL'], true)) {
            $refund->setStatus(WechatRefundAttempt::STATUS_FAILED);
            $this->entityManager->flush();
            $this->audit->record('refund-confirm', 'WechatRefundAttempt', $refund->getUuid(), 'failure', $paymentAttempt->getStoreUuid(), [
                'source' => $source,
                'status' => $status,
            ]);

            return 'failed';
        }

        $refund->setStatus(WechatRefundAttempt::STATUS_UNKNOWN);
        $this->entityManager->flush();

        return 'pending';
    }

    /**
     * Apply refunded accounting exactly once.
     *
     * The conditional marker update makes duplicate confirmations idempotent:
     * only the first confirmation mutates the Invoice and dispatches the event.
     *
     * @param array<string, mixed> $rawData
     */
    public function applyAccounting(WechatRefundAttempt $refund, array $rawData): bool
    {
        // Persist caller-set state (status, provider refund id) first: the
        // refresh() below would otherwise discard unflushed in-memory changes.
        $this->entityManager->flush();
        $claimed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE wechat_refund_attempt SET accounting_applied_at = :now WHERE id = :id AND accounting_applied_at IS NULL',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $refund->getId(),
            ],
        );
        if ($claimed !== 1) {
            return false;
        }

        $this->entityManager->refresh($refund);
        $paymentAttempt = $refund->getPaymentAttempt();
        if (!$paymentAttempt instanceof WechatPaymentAttempt) {
            throw new \RuntimeException('The refund has no payment attempt.');
        }
        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['uuid' => $paymentAttempt->getInvoiceUuid()]);
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('The invoice for this refund was not found.');
        }

        $newRefundedAmount = $invoice->getRefundedAmount() + $refund->getAmount();
        if ($newRefundedAmount > $invoice->getAmount()) {
            throw new \RuntimeException('The refund would exceed the invoice amount.');
        }

        $invoice->setRefundedAmount($newRefundedAmount);
        $invoice->appendExtraData('refund_'.$refund->getProviderRefundNo(), $this->sanitize($rawData));

        $transition = $newRefundedAmount >= $invoice->getAmount() ? 'refund' : 'partial_refund';
        if (!$this->invoiceWorkflow->can($invoice, $transition)) {
            throw new \RuntimeException(sprintf('The invoice cannot transition via "%s".', $transition));
        }
        if ($transition === 'refund') {
            $invoice->setRefundedAt(new \DateTimeImmutable());
        }
        $this->invoiceWorkflow->apply($invoice, $transition);
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new InvoiceRefundedEvent(
            $invoice,
            new PaymentRefundResult($invoice, $refund->getAmount(), $invoice->getStatus(), $refund->getProviderRefundId(), $rawData),
        ));

        return true;
    }

    public function pendingReserved(Invoice $invoice): int
    {
        $amounts = $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(r.amount), 0) FROM '.WechatRefundAttempt::class.' r JOIN r.paymentAttempt a WHERE a.invoiceUuid = :invoiceUuid AND r.status IN (:statuses)',
        )
            ->setParameter('invoiceUuid', $invoice->getUuid())
            ->setParameter('statuses', WechatRefundAttempt::PENDING_STATUSES)
            ->getSingleScalarResult();

        return (int) $amounts;
    }

    /** @param array<string, mixed> $data */
    private function verify(WechatRefundAttempt $refund, ResolvedWechatConfig $config, array $data): void
    {
        $refundNo = (string) ($data['out_refund_no'] ?? '');
        if ($refundNo !== '' && $refundNo !== $refund->getProviderRefundNo()) {
            throw new \InvalidArgumentException('The refund notification does not match this refund attempt.');
        }

        $refundAmount = (int) ($data['amount']['refund'] ?? $refund->getAmount());
        if ($refundAmount !== $refund->getAmount()) {
            throw new \InvalidArgumentException('The refund notification amount does not match this refund attempt.');
        }
    }

    private function generateRefundNo(): string
    {
        return sprintf('WRF%s%s', (new \DateTimeImmutable())->format('YmdHis'), strtoupper(bin2hex(random_bytes(6))));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    private function sanitize(array $payload): array
    {
        foreach (['password', 'secret', 'token', 'privateKey', 'signature'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
