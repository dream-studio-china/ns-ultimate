<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Refund;

use App\Payment\Entity\Invoice;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;

final class HuifuRefundWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuSigner $signer,
        private readonly HuifuClientInterface $client,
        private readonly HuifuResponseParser $responses,
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentAdjustmentRegistry $adjustments,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(Invoice $invoice, int $amount, string $reason, array $options = []): HuifuRefund
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Huifu refund amount must be positive.');
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
            $refund->markFailed('Huifu accepted a different refund amount.');
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
        $refund->markQueried();
        $refund->setResponseSnapshot($this->snapshot($responseData));
        $status = $this->responses->transStatus($responseData);
        if ($status === 'S' && $this->responses->successful($response)) {
            $responseAmount = $responseData['refund_amt'] ?? null;
            if (is_scalar($responseAmount) && $this->amountToCents((string) $responseAmount) !== $refund->getProviderAmount()) {
                $refund->markFailed('Huifu confirmed a different refund amount.');
                $this->entityManager->flush();
                return $refund;
            }
            $refund->markSucceeded($refund->getProviderAmount());
            $this->entityManager->flush();
            $this->applyToCore($refund);
        } elseif ($status === 'F') {
            $refund->markFailed($this->responses->message($response));
            $this->entityManager->flush();
        } elseif ($status === 'P') {
            $refund->markProcessing();
            $refund->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            $this->entityManager->flush();
        } else {
            $refund->markUnknown();
            $refund->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            $this->entityManager->flush();
        }

        return $refund;
    }

    public function applyToCore(HuifuRefund $refund): void
    {
        if ($refund->isCoreApplied()) {
            return;
        }
        if ($refund->getStatus() !== HuifuRefund::STATUS_SUCCEEDED) {
            throw new \RuntimeException('Only a confirmed Huifu refund can be applied to the invoice.');
        }
        $invoice = $refund->getInvoice();
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('The Huifu refund has no invoice.');
        }
        $this->invoices->refund(
            $invoice,
            $refund->getAmount(),
            'Huifu refund '.$refund->getRefundNo(),
            ['huifuRefundUuid' => $refund->getUuid()],
        );
        $refund->markCoreApplied();
        $this->entityManager->flush();
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
