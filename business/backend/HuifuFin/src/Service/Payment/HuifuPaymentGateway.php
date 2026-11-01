<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuOperationProof;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HuifuPaymentGateway implements PaymentGatewayInterface
{
    public const PAYMENT = 'huifu';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuRequestFactory $requests,
        private readonly HuifuClientInterface $client,
        private readonly HuifuResponseParser $responses,
        private readonly HuifuSigner $signer,
        private readonly HuifuOperationProof $proof,
    ) {
    }

    public static function getName(): string
    {
        return self::PAYMENT;
    }

    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $attemptUuid = $options['huifuAttemptUuid'] ?? null;
        if (!is_string($attemptUuid) || $attemptUuid === '') {
            throw new \RuntimeException('Huifu payments must be started through the HuifuFin Store payment endpoint.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Huifu payment amount must be positive.');
        }
        $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $attemptUuid]);
        if (!$attempt instanceof HuifuPaymentAttempt) {
            throw new \RuntimeException('The Huifu payment attempt was not found.');
        }
        if ($attempt->getInvoice()?->getUuid() !== $invoice->getUuid()) {
            throw new \RuntimeException('The Huifu payment attempt does not belong to this invoice.');
        }
        if (!$this->proof->verifyPayment($attempt->getUuid(), $options['huifuAttemptProof'] ?? null)) {
            throw new \RuntimeException('Huifu payments must be started through the HuifuFin Store payment endpoint.');
        }
        if ($attempt->getStatus() !== HuifuPaymentAttempt::STATUS_CREATED) {
            throw new \RuntimeException('The Huifu payment attempt has already been submitted or resolved.');
        }
        $merchant = $this->entityManager->getRepository(HuifuMerchant::class)
            ->findOneBy(['uuid' => $attempt->getMerchantUuid()]);
        if (!$merchant instanceof HuifuMerchant || !$merchant->isEnabled()) {
            throw new \RuntimeException('The Huifu merchant used by this payment is disabled or missing.');
        }
        $resolved = $this->profiles->resolveForAttempt($attempt, true);
        $attempt->setAmount($amount);
        $attempt->markSubmitted();

        $payload = $this->requests->paymentPayload($invoice, $attempt, $resolved['provider'], $resolved['credentials'], $options);
        $requestSnapshot = $payload['data'];
        unset($requestSnapshot['wx_data']);
        $attempt->setRequestSnapshot([
            'endpoint' => HuifuEndpoints::forChannel($attempt->getChannel()),
            'sys_id' => $payload['sys_id'],
            'product_id' => $payload['product_id'],
            'data' => $requestSnapshot,
        ]);
        $this->entityManager->flush();

        try {
            $response = $this->client->post(HuifuEndpoints::forChannel($attempt->getChannel()), $payload, $resolved['provider']->getEnvironment());
        } catch (\Throwable $exception) {
            $attempt->markUnknown();
            $attempt->setProviderResponse(null, 'The Huifu request result is unknown.');
            $this->entityManager->flush();
            throw $exception;
        }

        try {
            $this->responses->verify($response, $this->signer, $resolved['credentials']['huifu_public_key']);
            $data = $this->responses->data($response);
            if ($data === []) {
                throw new \RuntimeException('The Huifu response has no readable data.');
            }
        } catch (\Throwable $exception) {
            $attempt->markUnknown();
            $attempt->setProviderResponse(null, 'The Huifu response could not be verified; the result requires reconciliation.');
            $this->entityManager->flush();
            throw $exception;
        }
        $attempt->setProviderResponse($this->responses->code($response), $this->responses->message($response));
        $attempt->setResponseSnapshot($this->snapshot($data));
        if (isset($data['hf_seq_id']) && is_scalar($data['hf_seq_id'])) {
            $attempt->setHfSeqId((string) $data['hf_seq_id']);
        }
        if (isset($data['out_trans_id']) && is_scalar($data['out_trans_id'])) {
            $attempt->setTransactionId((string) $data['out_trans_id']);
        }
        $attempt->setProviderTransStatus($this->responses->transStatus($data));

        if (!$this->responses->successful($response)) {
            $attempt->markFailed($this->responses->message($response));
            $this->entityManager->flush();
            throw new \RuntimeException($this->responses->message($response) ?? 'Huifu rejected the payment request.');
        }
        if (isset($data['trans_amt']) && $this->amountToCents((string) $data['trans_amt']) !== $amount) {
            $attempt->markUnknown();
            $attempt->setProviderResponse(null, 'Huifu accepted a different payment amount; the result requires reconciliation.');
            $this->entityManager->flush();
            throw new \RuntimeException('Huifu accepted a different payment amount.');
        }

        $attempt->markPending();
        $this->entityManager->flush();

        return new PaymentResult(
            invoice: $invoice,
            status: Invoice::STATUS_PAYING,
            payUrl: isset($data['qr_code']) && is_string($data['qr_code']) ? $data['qr_code'] : null,
            qrCode: isset($data['qr_code']) && is_string($data['qr_code']) ? $data['qr_code'] : null,
            payload: [
                'attemptUuid' => $attempt->getUuid(),
                'reqSeqId' => $attempt->getReqSeqId(),
                'reqDate' => $attempt->getReqDate(),
                'channel' => $attempt->getChannel(),
                'tradeType' => $attempt->getTradeType(),
                'payInfo' => $data['pay_info'] ?? null,
            ],
            message: 'Huifu payment created',
        );
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        throw new PaymentVerificationException('Use the HuifuFin attempt-specific notification route.');
    }

    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $refundUuid = $options['huifuRefundUuid'] ?? null;
        if (!is_string($refundUuid) || $refundUuid === '') {
            throw new \RuntimeException('Huifu refunds must be started through the HuifuFin refund workflow.');
        }
        $refund = $this->entityManager->getRepository(HuifuRefund::class)->findOneBy(['uuid' => $refundUuid]);
        if (!$refund instanceof HuifuRefund || $refund->getInvoice()?->getUuid() !== $invoice->getUuid()) {
            throw new \RuntimeException('The confirmed Huifu refund record was not found.');
        }
        if ($refund->getStatus() !== HuifuRefund::STATUS_SUCCEEDED) {
            throw new \RuntimeException('Huifu has not confirmed the refund.');
        }
        if ($refund->isCoreApplied()) {
            throw new \RuntimeException('The Huifu refund has already been applied to the invoice.');
        }
        if (!$this->proof->verifyRefund($refund->getUuid(), $options['huifuRefundProof'] ?? null)) {
            throw new \RuntimeException('Huifu refunds must be applied through the HuifuFin refund workflow.');
        }
        if ($refund->getProviderAmount() !== $amount) {
            throw new \RuntimeException('The Huifu refund amount does not match the requested amount.');
        }
        $status = $refund->getAmount() >= ($invoice->getAmount() - $invoice->getRefundedAmount())
            ? Invoice::STATUS_REFUNDED
            : Invoice::STATUS_PARTIAL_REFUNDED;

        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $status,
            refundId: $refund->getProviderRefundId() ?? $refund->getRefundNo(),
            rawData: ['huifuRefundUuid' => $refund->getUuid(), 'refundNo' => $refund->getRefundNo()],
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new Response($result->responseBody, 200, ['Content-Type' => 'text/plain']);
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function snapshot(array $data): array
    {
        $keys = ['resp_code', 'resp_desc', 'trans_stat', 'hf_seq_id', 'out_trans_id', 'req_seq_id', 'req_date', 'trans_amt', 'huifu_id', 'qr_code'];
        $snapshot = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $snapshot[$key] = $data[$key];
            }
        }

        return $snapshot;
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return ((int) $matches[1]) * 100 + (int) $fraction;
    }
}
