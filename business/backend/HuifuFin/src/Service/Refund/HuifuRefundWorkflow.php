<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Refund;

use App\Payment\Entity\Invoice;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\InvoiceSourcePolicyInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuOperationProof;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class HuifuRefundWorkflow
{
    /** @var array<string, InvoiceSourcePolicyInterface> */
    private readonly array $sourcePolicies;

    /** @param iterable<InvoiceSourcePolicyInterface> $sourcePolicies */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuSigner $signer,
        private readonly HuifuClientInterface $client,
        private readonly HuifuResponseParser $responses,
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentAdjustmentRegistry $adjustments,
        private readonly HuifuOperationProof $proof,
        #[AutowireIterator('payment.invoice_source_policy')] iterable $sourcePolicies = [],
    ) {
        $policies = [];
        foreach ($sourcePolicies as $policy) $policies[$policy::getSourceType()] = $policy;
        $this->sourcePolicies = $policies;
    }

    /** @param array<string, mixed> $options */
    public function request(Invoice $invoice, int $amount, string $reason, array $options = []): HuifuRefund
    {
        $this->assertNoOuterTransaction();
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Huifu refund amount must be positive.');
        }
        if ($invoice->getId() === null) {
            throw new \RuntimeException('The Huifu refund invoice has not been persisted.');
        }

        $connection = $this->entityManager->getConnection();
        $refund = null;
        $attempt = null;
        $connection->beginTransaction();
        try {
            // Match Core's source-before-Invoice lock order, and reject an
            // ineligible source before any external refund is requested.
            $sourcePolicy = $this->sourcePolicies[$invoice->getSourceType()] ?? null;
            $sourcePolicy?->lock($invoice);
            /** @var Invoice|null $invoice */
            $invoice = $this->entityManager->find(Invoice::class, $invoice->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$invoice instanceof Invoice) {
                throw new \RuntimeException('The Huifu refund invoice was not found.');
            }
            $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $sourcePolicy?->assertRefundable($invoice);
            if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)) {
                throw new \RuntimeException('Only a paid invoice can be refunded through Huifu.');
            }
            $remaining = $invoice->getAmount() - $invoice->getRefundedAmount();
            if ($amount > $remaining) {
                throw new \InvalidArgumentException('Huifu refund amount exceeds the remaining paid amount.');
            }
            $reserved = $this->reservedAmount($invoice);
            if ($reserved + $amount > $remaining) {
                throw new \InvalidArgumentException('Another Huifu refund is already using the remaining refundable amount.');
            }
            $adjustmentAmount = $this->adjustments->sumAppliedAmount($invoice);
            if ($adjustmentAmount > 0 && $amount !== $invoice->getAmount()) {
                throw new \InvalidArgumentException('Adjusted invoices only support a full refund.');
            }
            $providerAmount = $amount - $adjustmentAmount;
            if ($providerAmount <= 0) {
                throw new \InvalidArgumentException('The refundable Huifu amount is zero after adjustments.');
            }
            $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(
                ['invoice' => $invoice, 'status' => HuifuPaymentAttempt::STATUS_SUCCEEDED],
                ['id' => 'DESC'],
            );
            if (!$attempt instanceof HuifuPaymentAttempt) {
                throw new \RuntimeException('No confirmed Huifu payment was found for this invoice.');
            }
            if ($attempt->getChannel() === 'quickpay') {
                throw new \RuntimeException('Huifu quick pay refunds are not enabled in this release.');
            }
            $resolved = $this->profiles->resolveForAttempt($attempt);
            $refund = new HuifuRefund(
                invoice: $invoice,
                attempt: $attempt,
                refundNo: self::refundNumber(),
                reqDate: (new \DateTimeImmutable())->format('Ymd'),
                amount: $amount,
                providerAmount: $providerAmount,
            );
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->refreshAfterRollback($refund, $invoice);
            throw $exception;
        }

        try {
            $data = [
                'req_date' => $refund->getReqDate(),
                'req_seq_id' => $refund->getRefundNo(),
                'huifu_id' => $attempt->getHuifuId(),
                'ord_amt' => number_format($providerAmount / 100, 2, '.', ''),
                'org_req_date' => $attempt->getReqDate(),
                'org_req_seq_id' => $attempt->getReqSeqId(),
            ];
            ksort($data);
            $sign = $this->signer->sign($data, $resolved['credentials']['merchant_private_key']);
            $payload = [
                'sys_id' => $resolved['provider']->getSysId(),
                'product_id' => $resolved['provider']->getProductId(),
                'data' => $data,
                'sign' => $sign,
            ];
        } catch (\Throwable $exception) {
            $refund->markCancelled();
            $refund->setProviderResponse(null, 'The Huifu refund request could not be signed or built.');
            $this->entityManager->flush();
            throw $exception;
        }
        $refund->setRequestSnapshot(['endpoint' => HuifuEndpoints::SCANPAY_REFUND, 'data' => $data]);
        $refund->markSubmitted();
        $this->entityManager->flush();

        try {
            $response = $this->client->post(HuifuEndpoints::SCANPAY_REFUND, $payload, $resolved['provider']->getEnvironment());
        } catch (\Throwable $exception) {
            $refund->markUnknown();
            $refund->setProviderResponse(null, 'The Huifu refund result is unknown.');
            $this->entityManager->flush();
            throw $exception;
        }
        $this->responses->verify($response, $this->signer, $resolved['credentials']['huifu_public_key']);
        $responseData = $this->responses->data($response);
        $refund->setProviderResponse($this->responses->code($response), $this->responses->message($response));
        $refund->setResponseSnapshot($this->snapshot($responseData));
        if (!$this->responses->successful($response)) {
            $refund->markFailed($this->responses->message($response));
            $this->entityManager->flush();
            throw new \RuntimeException($this->responses->message($response) ?? 'Huifu rejected the refund request.');
        }
        $responseAmount = $responseData['refund_amt'] ?? null;
        if (is_scalar($responseAmount) && $this->amountToCents((string) $responseAmount) !== $providerAmount) {
            $refund->markUnknown();
            $refund->setProviderResponse(null, 'Huifu accepted a different refund amount; the result requires reconciliation.');
            $this->entityManager->flush();
            throw new \RuntimeException('Huifu accepted a different refund amount.');
        }
        if ($this->responses->transStatus($responseData) === 'S') {
            $refund->markSucceeded($providerAmount);
            $this->entityManager->flush();
            $this->applyToCore($refund);
        } else {
            $refund->markProcessing();
            $refund->setNextQueryAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            try {
                $this->refresh($refund);
            } catch (\Throwable) {
                $refund->setProviderResponse(
                    $refund->getResponseCode(),
                    trim((string) $refund->getResponseMessage().' Refund confirmation query is pending.'),
                );
                $this->entityManager->flush();
            }
        }

        return $refund;
    }

    public function refresh(HuifuRefund $refund): HuifuRefund
    {
        $this->assertNoOuterTransaction();
        if (in_array($refund->getStatus(), [HuifuRefund::STATUS_FAILED, HuifuRefund::STATUS_CANCELLED], true)) {
            return $refund;
        }
        if ($refund->getStatus() === HuifuRefund::STATUS_SUCCEEDED) {
            $this->applyToCore($refund);
            return $refund;
        }
        $attempt = $refund->getAttempt();
        if (!$attempt instanceof HuifuPaymentAttempt) {
            throw new \RuntimeException('The Huifu refund has no original payment attempt.');
        }
        $resolved = $this->profiles->resolveForAttempt($attempt);
        $data = [
            'req_date' => $refund->getReqDate(),
            'req_seq_id' => $refund->getRefundNo(),
            'huifu_id' => $attempt->getHuifuId(),
            'org_req_date' => $attempt->getReqDate(),
            'org_req_seq_id' => $attempt->getReqSeqId(),
        ];
        ksort($data);
        $sign = $this->signer->sign($data, $resolved['credentials']['merchant_private_key']);
        $payload = [
            'sys_id' => $resolved['provider']->getSysId(),
            'product_id' => $resolved['provider']->getProductId(),
            'data' => $data,
            'sign' => $sign,
        ];
        $response = $this->client->post(HuifuEndpoints::SCANPAY_REFUND_QUERY, $payload, $resolved['provider']->getEnvironment());
        $this->responses->verify($response, $this->signer, $resolved['credentials']['huifu_public_key']);
        $responseData = $this->responses->data($response);

        $connection = $this->entityManager->getConnection();
        $shouldApply = false;
        $connection->beginTransaction();
        try {
            /** @var HuifuRefund|null $refund */
            $refund = $this->entityManager->find(HuifuRefund::class, $refund->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$refund instanceof HuifuRefund) {
                throw new \RuntimeException('The Huifu refund record was not found.');
            }
            if ($refund->getStatus() === HuifuRefund::STATUS_SUCCEEDED) {
                $connection->commit();
                $this->applyToCore($refund);
                return $refund;
            }
            if (in_array($refund->getStatus(), [HuifuRefund::STATUS_FAILED, HuifuRefund::STATUS_CANCELLED], true)) {
                $connection->commit();
                return $refund;
            }
            $refund->markQueried();
            $refund->setResponseSnapshot($this->snapshot($responseData));
            $status = $this->responses->transStatus($responseData);
            if ($status === 'S' && $this->responses->successful($response)) {
                $responseAmount = $responseData['refund_amt'] ?? null;
                if (is_scalar($responseAmount) && $this->amountToCents((string) $responseAmount) !== $refund->getProviderAmount()) {
                    $refund->markUnknown();
                    $refund->setProviderResponse(null, 'Huifu confirmed a different refund amount; the result requires reconciliation.');
                } else {
                    $refund->markSucceeded($refund->getProviderAmount());
                    $shouldApply = true;
                }
            } elseif ($status === 'F') {
                $refund->markFailed($this->responses->message($response));
            } elseif ($status === 'P') {
                $refund->markProcessing();
                $refund->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            } else {
                $refund->markUnknown();
                $refund->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->refreshAfterRollback($refund, null);
            throw $exception;
        }
        if ($shouldApply) {
            $this->applyToCore($refund);
        }

        return $refund;
    }

    public function applyToCore(HuifuRefund $refund): void
    {
        $this->assertNoOuterTransaction();
        if ($refund->getId() === null) {
            throw new \RuntimeException('The Huifu refund has not been persisted.');
        }
        $connection = $this->entityManager->getConnection();
        $invoice = null;
        $connection->beginTransaction();
        try {
            /** @var HuifuRefund|null $refund */
            $refund = $this->entityManager->find(HuifuRefund::class, $refund->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$refund instanceof HuifuRefund) {
                throw new \RuntimeException('The Huifu refund record was not found.');
            }
            $this->entityManager->refresh($refund, LockMode::PESSIMISTIC_WRITE);
            if ($refund->isCoreApplied()) {
                $connection->commit();
                return;
            }
            if ($refund->getStatus() !== HuifuRefund::STATUS_SUCCEEDED) {
                throw new \RuntimeException('Only a confirmed Huifu refund can be applied to the invoice.');
            }
            $invoice = $refund->getInvoice();
            if (!$invoice instanceof Invoice || $invoice->getId() === null) {
                throw new \RuntimeException('The Huifu refund has no invoice.');
            }
            // Commit validation before Core reserves/finalizes the refund in its
            // own transactions. No provider/core side effects run under this lock.
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            $this->refreshAfterRollback($refund, $invoice);
            throw $exception;
        }

        try {
            $this->invoices->refund(
                $invoice,
                $refund->getAmount(),
                'Huifu refund '.$refund->getRefundNo(),
                [
                    // Recover a committed Core result if the final local marker
                    // fails. Never reserve a second Core refund on retry.
                    'idempotencyKey' => 'huifu-refund:'.$refund->getUuid(),
                    'huifuRefundUuid' => $refund->getUuid(),
                    'huifuRefundProof' => $this->proof->refund($refund->getUuid()),
                ],
            );

            $connection->beginTransaction();
            $this->entityManager->refresh($refund, LockMode::PESSIMISTIC_WRITE);
            if (!$refund->isCoreApplied()) $refund->markCoreApplied();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->refreshAfterRollback($refund, $invoice);
            throw $exception;
        }
    }

    private function assertNoOuterTransaction(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Huifu refund operations must run outside an outer transaction.');
        }
    }

    private function refreshAfterRollback(mixed $refund, mixed $invoice): void
    {
        try {
            if ($refund instanceof HuifuRefund) {
                $this->entityManager->refresh($refund);
            }
            if ($invoice instanceof Invoice) {
                $this->entityManager->refresh($invoice);
            }
        } catch (\Throwable) {
            // The caller still receives the original failure.
        }
    }

    private function reservedAmount(Invoice $invoice): int
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(r.amount), 0)')
            ->from(HuifuRefund::class, 'r')
            ->where('r.invoice = :invoice')
            ->andWhere('r.coreApplied = false')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('invoice', $invoice)
            ->setParameter('statuses', [
                HuifuRefund::STATUS_CREATED,
                HuifuRefund::STATUS_SUBMITTED,
                HuifuRefund::STATUS_PROCESSING,
                HuifuRefund::STATUS_UNKNOWN,
                HuifuRefund::STATUS_SUCCEEDED,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    private static function refundNumber(): string
    {
        return 'HFR'.(new \DateTimeImmutable())->format('YmdHis').strtoupper(bin2hex(random_bytes(5)));
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return ((int) $matches[1]) * 100 + (int) $fraction;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function snapshot(array $data): array
    {
        $keys = ['resp_code', 'resp_desc', 'req_seq_id', 'req_date', 'huifu_id', 'trans_stat', 'refund_amt', 'out_refund_id', 'hf_seq_id'];
        $snapshot = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $snapshot[$key] = $data[$key];
            }
        }

        return $snapshot;
    }
}
