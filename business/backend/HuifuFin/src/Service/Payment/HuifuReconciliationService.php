<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;

final class HuifuReconciliationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuSigner $signer,
        private readonly HuifuClientInterface $client,
        private readonly HuifuResponseParser $responses,
        private readonly InvoiceServiceInterface $invoices,
        private readonly HuifuRefundWorkflow $refunds,
    ) {
    }

    public function queryPayment(HuifuPaymentAttempt $attempt): HuifuPaymentAttempt
    {
        if (in_array($attempt->getStatus(), [HuifuPaymentAttempt::STATUS_SUCCEEDED, HuifuPaymentAttempt::STATUS_CLOSED], true)) {
            return $attempt;
        }
        $resolved = $this->profiles->resolveForAttempt($attempt);
        $data = [
            'huifu_id' => $attempt->getHuifuId(),
            'org_req_date' => $attempt->getReqDate(),
            'org_req_seq_id' => $attempt->getReqSeqId(),
        ];
        ksort($data);
        $payload = [
            'sys_id' => $resolved['provider']->getSysId(),
            'product_id' => $resolved['provider']->getProductId(),
            'data' => $data,
            'sign' => $this->signer->sign($data, $resolved['credentials']['merchant_private_key']),
        ];
        $response = $this->client->post(HuifuEndpoints::SCANPAY_QUERY, $payload, $resolved['provider']->getEnvironment());
        $this->responses->verify($response, $this->signer, $resolved['credentials']['huifu_public_key']);
        $responseData = $this->responses->data($response);

        $connection = $this->entityManager->getConnection();
        $lockedInvoice = null;
        $connection->beginTransaction();
        try {
            /** @var HuifuPaymentAttempt|null $attempt */
            $attempt = $this->entityManager->find(
                HuifuPaymentAttempt::class,
                $attempt->getId(),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$attempt instanceof HuifuPaymentAttempt) {
                throw new \RuntimeException('The Huifu payment attempt was not found.');
            }
            if (in_array($attempt->getStatus(), [HuifuPaymentAttempt::STATUS_SUCCEEDED, HuifuPaymentAttempt::STATUS_CLOSED], true)) {
                $connection->commit();

                return $attempt;
            }
            $attempt->markQueried();
            $attempt->setResponseSnapshot($this->snapshot($responseData));
            $status = $this->responses->transStatus($responseData);
            if ($status === 'S' && $this->responses->successful($response)) {
                $amount = $this->amountToCents((string) ($responseData['trans_amt'] ?? ''));
                $invoice = $attempt->getInvoice();
                $lockedInvoice = $invoice;
                if ($invoice instanceof Invoice && $amount > 0) {
                    $result = new PaymentNotifyResult(
                        payment: HuifuPaymentGateway::PAYMENT,
                        outTradeNo: $invoice->getOutTradeNo(),
                        status: Invoice::STATUS_PAID,
                        amount: $amount,
                        currency: strtoupper((string) ($responseData['currency'] ?? $invoice->getCurrency())),
                        transactionId: isset($responseData['out_trans_id']) ? (string) $responseData['out_trans_id'] : null,
                        paidAt: $this->date($responseData['end_time'] ?? null),
                        rawData: $this->snapshot($responseData),
                    );
                    $this->invoices->handleNotifyResult($result);
                    $attempt->markSucceeded($result->transactionId);
                }
            } elseif ($status === 'F') {
                $attempt->markFailed($this->responses->message($response));
            } elseif ($status === 'P') {
                $attempt->markPending();
                $attempt->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            } else {
                $attempt->markUnknown();
                $attempt->setNextQueryAt(new \DateTimeImmutable('+5 minutes'));
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            try {
                if ($attempt instanceof HuifuPaymentAttempt) {
                    $this->entityManager->refresh($attempt);
                }
                if ($lockedInvoice instanceof Invoice) {
                    $this->entityManager->refresh($lockedInvoice);
                }
            } catch (\Throwable) {
                // The caller still receives the original failure.
            }
            throw $exception;
        }

        return $attempt;
    }

    public function queryRefund(HuifuRefund $refund): HuifuRefund
    {
        return $this->refunds->refresh($refund);
    }

    /** @return list<HuifuPaymentAttempt> */
    public function duePayments(int $limit = 50): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(HuifuPaymentAttempt::class, 'a')
            ->where('a.status IN (:statuses) OR (a.status = :created AND a.createdAt <= :staleBefore)')
            ->andWhere('a.nextQueryAt IS NULL OR a.nextQueryAt <= :now')
            ->setParameter('statuses', [
                HuifuPaymentAttempt::STATUS_SUBMITTED,
                HuifuPaymentAttempt::STATUS_PENDING,
                HuifuPaymentAttempt::STATUS_UNKNOWN,
            ])
            ->setParameter('created', HuifuPaymentAttempt::STATUS_CREATED)
            ->setParameter('staleBefore', new \DateTimeImmutable('-5 minutes'))
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<HuifuRefund> */
    public function dueRefunds(int $limit = 50): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(HuifuRefund::class, 'r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.coreApplied = false')
            ->andWhere('r.nextQueryAt IS NULL OR r.nextQueryAt <= :now')
            ->setParameter('statuses', [
                HuifuRefund::STATUS_SUBMITTED,
                HuifuRefund::STATUS_PROCESSING,
                HuifuRefund::STATUS_UNKNOWN,
                HuifuRefund::STATUS_SUCCEEDED,
            ])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return ((int) $matches[1]) * 100 + (int) $fraction;
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && preg_match('/^\d{14}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('YmdHis', $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function snapshot(array $data): array
    {
        $keys = ['resp_code', 'resp_desc', 'req_seq_id', 'req_date', 'huifu_id', 'trans_amt', 'trans_stat', 'out_trans_id', 'hf_seq_id', 'end_time'];
        $snapshot = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $snapshot[$key] = $data[$key];
            }
        }

        return $snapshot;
    }
}
