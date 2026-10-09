<?php

declare(strict_types=1);

namespace App\Payment\Service\Gateway;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MockGateway implements PaymentGatewayInterface
{
    public static function getName(): string
    {
        return Invoice::PAYMENT_MOCK;
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $status = !empty($options['autoPaid']) ? Invoice::STATUS_PAID : Invoice::STATUS_PAYING;
        return new PaymentResult(
            invoice: $invoice,
            status: $status,
            payUrl: '/mock/pay/' . $invoice->getOutTradeNo(),
            payload: [
                'gateway' => self::getName(),
                'outTradeNo' => $invoice->getOutTradeNo(),
                'amount' => $amount,
                'transactionId' => $options['transactionId'] ?? 'mock-' . $invoice->getOutTradeNo(),
            ],
            message: 'Mock payment created',
        );
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        if (($data['secret'] ?? 'mock') !== 'mock') {
            throw new PaymentVerificationException('Invalid mock payment secret.');
        }

        return new PaymentNotifyResult(
            payment: self::getName(),
            outTradeNo: (string) ($data['outTradeNo'] ?? ''),
            status: (string) ($data['status'] ?? Invoice::STATUS_PAID),
            amount: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'CNY'),
            transactionId: $data['transactionId'] ?? ('mock-' . ($data['outTradeNo'] ?? '')),
            paidAt: new \DateTimeImmutable(),
            rawData: $data,
        );
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $amount >= ($paidAmount - $invoice->getRefundedAmount()) ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PARTIAL_REFUNDED,
            refundId: $options['refundId'] ?? 'mock-refund-' . $invoice->getOutTradeNo() . '-' . ($invoice->getRefundedAmount() + $amount),
            rawData: ['reason' => $reason, 'amount' => $amount, 'paidAmount' => $paidAmount],
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new Response($result->responseBody, 200, ['Content-Type' => 'text/plain']);
    }
}
