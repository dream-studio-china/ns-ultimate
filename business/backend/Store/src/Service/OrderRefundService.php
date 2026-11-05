<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use NsUltimate\Business\Store\DTO\OrderRefundReceipt;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderRefundService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceServiceInterface $invoices,
        private readonly HuifuRefundWorkflow $huifu,
        private readonly LoggerInterface $logger,
        private readonly OrderRefundMetadata $metadata,
    ) {}

    public function order(string $storeUuid, string $orderUuid): Order
    {
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        $scope = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $orderUuid, 'storeUuid' => $storeUuid]);
        if (!$order instanceof Order || !$scope instanceof OrderStoreLifecycle) throw new HttpException(404, 'order_not_found');
        return $order;
    }

    public function request(string $storeUuid, string $orderUuid, string $actorUuid, int $amount, string $reason, string $key): OrderRefundReceipt
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) throw new \LogicException('Order refunds require an independent transaction.');
        $connection->beginTransaction();
        try {
            $order = $this->order($storeUuid, $orderUuid);
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $hash = hash('sha256', $key);
            $entries = $this->metadata->entries($orderUuid);
            if (isset($entries[$hash])) {
                $existing = OrderRefundReceipt::fromArray($order, $storeUuid, $hash, $entries[$hash]);
                if ($existing->amount !== $amount || $existing->reason !== $reason) throw new HttpException(409, 'refund_idempotency_conflict');
                $connection->commit();
                return $existing;
            }
            if ($order->getStatus() !== Order::STATUS_PAID) throw new HttpException(409, 'order_not_refundable');
            if ($this->hasPending($orderUuid)) throw new HttpException(409, 'refund_pending');
            $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
            if (!$invoice instanceof Invoice || $invoice->getSourceType() !== 'trade_order' || $invoice->getSourceId() !== $orderUuid) throw new HttpException(409, 'order_invoice_mismatch');
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)
                || $amount > $invoice->getAmount() - $invoice->getRefundedAmount()) throw new HttpException(409, 'invalid_refund_amount');
            $refund = new OrderRefundReceipt($storeUuid, $orderUuid, $invoice->getUuid(), $actorUuid, $hash, $amount, $reason, $invoice->getPayment() ?? '');
            $this->metadata->save($order, $refund);
            $connection->commit();
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            throw $error;
        }

        // The reservation commits before any provider side effect. Replays only
        // read it; they never submit another provider refund, even after a crash.
        try {
            if ($refund->payment === 'huifu') {
                $this->huifu->request($invoice, $amount, $reason, ['refundNo' => $refund->huifuNumber()]);
            } else {
                $this->invoices->refund($invoice, $amount, $reason, ['idempotencyKey' => $refund->coreKey()]);
            }
        } catch (\Throwable $error) {
            $this->logger->warning('Store order refund requires outcome inspection.', ['refundUuid' => $refund->uuid, 'exception' => $error]);
            // Both workflows persist their attempt before contacting the provider.
            // Without one, this is a confirmed pre-submission rejection.
            if ($this->providerRefund($refund) === null && $this->coreAttempt($refund) === null) {
                $refund->status = 'failed';
                $connection->beginTransaction();
                try {
                    $order = $this->order($storeUuid, $orderUuid);
                    $this->metadata->save($order, $refund);
                    $connection->commit();
                } catch (\Throwable $writeError) {
                    if ($connection->isTransactionActive()) $connection->rollBack();
                    throw $writeError;
                }
            }
        }
        return $refund;
    }

    public function coreAttempt(OrderRefundReceipt $refund): ?InvoiceRefundAttempt
    {
        return $this->current($this->em->createQueryBuilder()->select('a')->from(InvoiceRefundAttempt::class, 'a')
            ->where('a.attemptKey = :key')->setParameter('key', hash('sha256', $refund->invoiceUuid."\0".$refund->coreKey()))->getQuery())->getOneOrNullResult();
    }

    private function providerRefund(OrderRefundReceipt $refund): ?HuifuRefund
    {
        return $refund->payment === 'huifu' ? $this->current($this->em->createQueryBuilder()->select('r')->from(HuifuRefund::class, 'r')
            ->where('r.refundNo = :number')->setParameter('number', $refund->huifuNumber())->getQuery())->getOneOrNullResult() : null;
    }

    public function status(OrderRefundReceipt $refund): string
    {
        if ($refund->status === 'failed') return 'failed';
        if ($refund->payment === 'huifu') {
            $provider = $this->providerRefund($refund);
            if ($provider === null) return 'processing';
            if ($provider->isCoreApplied()) return 'succeeded';
            return match ($provider->getStatus()) {
                HuifuRefund::STATUS_FAILED, HuifuRefund::STATUS_CANCELLED => 'failed',
                HuifuRefund::STATUS_UNKNOWN => 'unknown',
                default => 'processing',
            };
        }
        $attempt = $this->coreAttempt($refund);
        if ($attempt === null) return 'processing';
        return match ($attempt->getStatus()) {
            InvoiceRefundAttempt::STATUS_COMPLETED => 'succeeded',
            InvoiceRefundAttempt::STATUS_UNKNOWN => 'unknown',
            default => 'processing',
        };
    }

    public function hasPending(string $orderUuid): bool
    {
        if ($this->em->getRepository(InvoiceRefundAttempt::class)->hasUnresolvedForSource('trade_order', $orderUuid)) return true;
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        foreach ($this->metadata->entries($orderUuid) as $hash => $entry) {
            if (!$order instanceof Order) break;
            $refund = OrderRefundReceipt::fromArray($order, '', $hash, $entry);
            if (in_array($this->status($refund), ['processing', 'unknown'], true)) return true;
        }
        // Protect historical Huifu refunds created before the unified endpoint.
        return $this->current($this->em->createQueryBuilder()->select('r')->from(HuifuRefund::class, 'r')
            ->join('r.invoice', 'i')->where('i.sourceType = :source AND i.sourceId = :order')
            ->andWhere('r.coreApplied = false AND r.status NOT IN (:terminal)')
            ->setParameter('source', 'trade_order')->setParameter('order', $orderUuid)
            ->setParameter('terminal', [HuifuRefund::STATUS_FAILED, HuifuRefund::STATUS_CANCELLED])
            ->setMaxResults(1)->getQuery())->getOneOrNullResult() !== null;
    }

    public function find(string $storeUuid, string $orderUuid, string $refundUuid): OrderRefundReceipt
    {
        $order = $this->order($storeUuid, $orderUuid);
        foreach ($this->metadata->entries($orderUuid) as $hash => $entry) {
            if ($entry['uuid'] === $refundUuid) return OrderRefundReceipt::fromArray($order, $storeUuid, $hash, $entry);
        }
        throw new HttpException(404, 'refund_not_found');
    }

    private function current(Query $query): Query
    {
        // A plain SELECT can use an older MySQL REPEATABLE READ snapshot even
        // after waiting for the source lock. Mutation guards need current reads.
        if ($this->em->getConnection()->isTransactionActive() && !$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        return $query->setHint(Query::HINT_REFRESH, true);
    }

    /** @return array<string, mixed> */
    public function summary(OrderRefundReceipt $refund): array
    {
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $refund->invoiceUuid]);
        if ($invoice instanceof Invoice) $this->em->refresh($invoice);
        return ['uuid' => $refund->uuid, 'orderUuid' => $refund->orderUuid, 'invoiceUuid' => $refund->invoiceUuid,
            'amount' => $refund->amount, 'reason' => $refund->reason, 'status' => $this->status($refund),
            'actorUuid' => $refund->actorUuid, 'createdAt' => $refund->createdAt->format(DATE_ATOM),
            'invoiceStatus' => $invoice?->getStatus(), 'refundedAmount' => $invoice?->getRefundedAmount()];
    }
}
