<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuWebhookEvent;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use Symfony\Component\HttpFoundation\Request;

final class HuifuNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuSigner $signer,
        private readonly HuifuResponseParser $responses,
        private readonly InvoiceServiceInterface $invoices,
    ) {
    }

    public function handle(Request $request, string $attemptUuid): string
    {
        $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $attemptUuid]);
        if (!$attempt instanceof HuifuPaymentAttempt) {
            throw new PaymentVerificationException('The Huifu payment attempt was not found.');
        }
        $content = $this->content($request);
        $outerCode = $content['resp_code'] ?? null;
        if (is_scalar($outerCode) && (string) $outerCode !== '00000000') {
            throw new PaymentVerificationException('Huifu notification response code is not successful.');
        }
        $respData = $content['resp_data'] ?? null;
        if (is_array($respData)) {
            $data = $respData;
            $signedData = json_encode($respData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif (is_string($respData)) {
            $decoded = json_decode($respData, true);
            $data = is_array($decoded) ? $decoded : [];
            $signedData = $respData;
        } else {
            throw new PaymentVerificationException('Huifu notification is missing resp_data.');
        }
        $signature = $content['sign'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new PaymentVerificationException('Huifu notification is not signed.');
        }
        $credentials = $this->profiles->resolveForAttempt($attempt)['credentials'];
        $publicKey = $credentials['async_public_key'] ?? $credentials['huifu_public_key'] ?? '';
        if ($publicKey === '' || !$this->signer->verify($signedData, $signature, $publicKey)) {
            throw new PaymentVerificationException('Huifu notification signature verification failed.');
        }
        if (($data['req_seq_id'] ?? null) !== $attempt->getReqSeqId()) {
            throw new PaymentVerificationException('Huifu notification request sequence does not match the payment attempt.');
        }
        if (isset($data['resp_code']) && !in_array((string) $data['resp_code'], ['00000000', '00000100'], true)) {
            throw new PaymentVerificationException('Huifu notification transaction response code is not successful.');
        }
        if (isset($data['huifu_id']) && (string) $data['huifu_id'] !== $attempt->getHuifuId()) {
            throw new PaymentVerificationException('Huifu notification merchant does not match the payment attempt.');
        }
        $amount = $this->amountToCents((string) ($data['trans_amt'] ?? ''));
        $currency = strtoupper((string) ($data['currency'] ?? $attempt->getCurrency()));
        if ($amount <= 0) {
            throw new PaymentVerificationException('Huifu notification has an invalid amount.');
        }
        if ($amount > ($attempt->getInvoice()?->getAmount() ?? 0)) {
            throw new PaymentVerificationException('Huifu notification amount exceeds the invoice amount.');
        }
        $eventKey = sprintf(
            'payment:%s:%s:%s:%s',
            $attempt->getUuid(),
            (string) ($data['req_seq_id'] ?? ''),
            (string) ($data['trans_stat'] ?? ''),
            (string) ($data['out_trans_id'] ?? ''),
        );
        $event = $this->entityManager->getRepository(HuifuWebhookEvent::class)->findOneBy(['eventKey' => $eventKey]);
        if ($event instanceof HuifuWebhookEvent && $event->isProcessed()) {
            return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
        }
        if (!$event instanceof HuifuWebhookEvent) {
            $event = new HuifuWebhookEvent(
                eventKey: $eventKey,
                type: 'payment',
                attempt: $attempt,
                bodyHash: hash('sha256', $request->getContent()),
                payload: $this->snapshot($data),
            );
            $this->entityManager->persist($event);
            try {
                $this->entityManager->flush();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
            }
        }

        $transStatus = $this->responses->transStatus($data);
        $attempt->setProviderResponse((string) ($data['resp_code'] ?? ''), (string) ($data['resp_desc'] ?? ''));
        $attempt->setProviderTransStatus($transStatus);
        if (isset($data['hf_seq_id']) && is_scalar($data['hf_seq_id'])) {
            $attempt->setHfSeqId((string) $data['hf_seq_id']);
        }
        $result = null;
        try {
            if ($transStatus === 'S') {
                $result = new PaymentNotifyResult(
                    payment: HuifuPaymentGateway::PAYMENT,
                    outTradeNo: $attempt->getInvoice()?->getOutTradeNo() ?? '',
                    status: Invoice::STATUS_PAID,
                    amount: $amount,
                    currency: $currency,
                    transactionId: isset($data['out_trans_id']) ? (string) $data['out_trans_id'] : null,
                    paidAt: $this->date($data['end_time'] ?? null),
                    rawData: $this->snapshot($data),
                    responseBody: 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? ''),
                );
                $this->invoices->handleNotifyResult($result);
                $attempt->markSucceeded($result->transactionId, $result->paidAt);
            } elseif ($transStatus === 'F') {
                $result = new PaymentNotifyResult(
                    payment: HuifuPaymentGateway::PAYMENT,
                    outTradeNo: $attempt->getInvoice()?->getOutTradeNo() ?? '',
                    status: Invoice::STATUS_FAILED,
                    amount: $amount,
                    currency: $currency,
                    rawData: $this->snapshot($data),
                    responseBody: 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? ''),
                );
                $this->invoices->handleNotifyResult($result);
                $attempt->markFailed((string) ($data['resp_desc'] ?? 'Huifu payment failed.'));
            } else {
                $attempt->markPending();
            }
            $event->markProcessed();
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $event->markError($exception->getMessage());
            $this->entityManager->flush();
            throw $exception;
        }

        return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function content(Request $request): array
    {
        $content = $request->request->all();
        if ($content === []) {
            parse_str($request->getContent(), $content);
        }
        if ($content === []) {
            $decoded = json_decode($request->getContent(), true);
            $content = is_array($decoded) ? $decoded : [];
        }

        return $content;
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
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
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
