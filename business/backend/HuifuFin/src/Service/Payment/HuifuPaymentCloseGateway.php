<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\Payment\Contract\PaymentCloseGatewayInterface;
use NsUltimate\Business\Payment\DTO\PaymentCloseResult;

/** Scanpay closure capability; Invoice and order policy remain channel-independent. */
final class HuifuPaymentCloseGateway implements PaymentCloseGatewayInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuClientInterface $client,
        private readonly HuifuSigner $signer,
        private readonly HuifuResponseParser $responses,
    ) {}

    public static function payment(): string { return 'huifu'; }

    public function close(Invoice $invoice): PaymentCloseResult
    {
        // The caller owns the source/Invoice transaction and serializes initiation.
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Huifu closure requires the caller payment transaction.');
        $attempts = $this->em->createQueryBuilder()->select('a')->from(HuifuPaymentAttempt::class, 'a')->where('a.invoice = :invoice')
            ->setParameter('invoice', $invoice)->orderBy('a.id', 'ASC')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
        if ($attempts === [] || count($attempts) > 10) return new PaymentCloseResult();
        foreach ($attempts as $attempt) {
            if (!$attempt instanceof HuifuPaymentAttempt || !in_array($attempt->getChannel(), ['native', 'jspay'], true)) return new PaymentCloseResult();
        }
        foreach ($attempts as $attempt) {
            $original = ['huifu_id' => $attempt->getHuifuId(), 'org_req_date' => $attempt->getReqDate(), 'org_req_seq_id' => $attempt->getReqSeqId()];
            $data = $this->call(HuifuEndpoints::SCANPAY_QUERY, $original, $attempt);
            $this->validatePayment($data, $attempt);
            $status = $this->responses->transStatus($data);
            if ($status === 'S') return $this->collected($invoice, $attempt, $data);
            if ($status === 'F') {
                $attempt->markFailed($this->responses->message(['data' => $data]));
                continue;
            }
            if (!in_array($status, ['I', 'P'], true)) return new PaymentCloseResult();

            // A repeat closes the same original payment, never starts a new payment.
            $closeRequest = $original + ['req_date' => (new \DateTimeImmutable())->format('Ymd'), 'req_seq_id' => 'HFC'.str_replace('-', '', $attempt->getUuid())];
            $closed = $this->call(HuifuEndpoints::SCANPAY_CLOSE, $closeRequest, $attempt);
            foreach ($closeRequest as $field => $expected) {
                if (array_key_exists($field, $closed) && (!is_scalar($closed[$field]) || (string) $closed[$field] !== $expected)) {
                    throw new \RuntimeException('Huifu close response identity mismatch.');
                }
            }
            if (!$this->responses->successful(['data' => $closed])) return new PaymentCloseResult();

            // An accepted close is not proof. Query the original payment again;
            // only a verified terminal failure makes it safe to release locally.
            $data = $this->call(HuifuEndpoints::SCANPAY_QUERY, $original, $attempt);
            $this->validatePayment($data, $attempt);
            $status = $this->responses->transStatus($data);
            if ($status === 'S') return $this->collected($invoice, $attempt, $data);
            if ($status !== 'F') return new PaymentCloseResult();
            $attempt->markClosed();
        }
        return new PaymentCloseResult(closed: true);
    }

    /** @param array<string, string> $data
     * @return array<string, mixed> */
    private function call(string $endpoint, array $data, HuifuPaymentAttempt $attempt): array
    {
        $resolved = $this->profiles->resolveForAttempt($attempt);
        ksort($data);
        $response = $this->client->post($endpoint, [
            'sys_id' => $resolved['provider']->getSysId(), 'product_id' => $resolved['provider']->getProductId(),
            'data' => $data, 'sign' => $this->signer->sign($data, $resolved['credentials']['merchant_private_key']),
        ], $resolved['provider']->getEnvironment());
        $this->responses->verify($response, $this->signer, $resolved['credentials']['huifu_public_key']);
        return $this->responses->data($response);
    }

    /** @param array<string, mixed> $data */
    private function validatePayment(array $data, HuifuPaymentAttempt $attempt): void
    {
        if (!$this->responses->successful(['data' => $data]) || ($data['huifu_id'] ?? null) !== $attempt->getHuifuId()) {
            throw new \RuntimeException('Huifu payment confirmation was unsuccessful or mismatched.');
        }
        if (array_key_exists('currency', $data) && (!is_string($data['currency']) || strtoupper($data['currency']) !== $attempt->getCurrency())) {
            throw new \RuntimeException('Huifu payment confirmation currency mismatch.');
        }
        foreach (['req_date' => $attempt->getReqDate(), 'req_seq_id' => $attempt->getReqSeqId(), 'org_req_date' => $attempt->getReqDate(), 'org_req_seq_id' => $attempt->getReqSeqId()] as $field => $expected) {
            if (array_key_exists($field, $data) && (!is_scalar($data[$field]) || (string) $data[$field] !== $expected)) throw new \RuntimeException('Huifu payment confirmation identity mismatch.');
        }
        $amount = $data['trans_amt'] ?? null;
        if (!is_string($amount) || !preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $amount, $matches)
            || (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0') !== $attempt->getAmount()) throw new \RuntimeException('Huifu payment confirmation amount mismatch.');
        $attempt->markQueried();
        $attempt->setProviderResponse($this->responses->code(['data' => $data]), $this->responses->message(['data' => $data]));
        $attempt->setProviderTransStatus($this->responses->transStatus($data));
        $attempt->setResponseSnapshot(array_intersect_key($data, array_flip(['resp_code', 'resp_desc', 'req_date', 'req_seq_id', 'org_req_date', 'org_req_seq_id', 'huifu_id', 'trans_amt', 'trans_stat', 'trans_status', 'out_trans_id', 'end_time'])));
    }

    /** @param array<string, mixed> $data */
    private function collected(Invoice $invoice, HuifuPaymentAttempt $attempt, array $data): PaymentCloseResult
    {
        $transactionId = isset($data['out_trans_id']) && is_string($data['out_trans_id']) ? $data['out_trans_id'] : null;
        $attempt->markSucceeded($transactionId);
        return new PaymentCloseResult(payment: new PaymentNotifyResult(
            payment: self::payment(), outTradeNo: $invoice->getOutTradeNo(), status: Invoice::STATUS_PAID,
            amount: $attempt->getAmount(), currency: $attempt->getCurrency(), transactionId: $transactionId,
            rawData: $attempt->getResponseSnapshot() ?? [],
        ));
    }
}
