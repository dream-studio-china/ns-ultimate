<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\Gateway\MockGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[Group('low-value')]
final class MockGatewayTest extends TestCase
{
    public function testPayNotifyRefundAndResponse(): void
    {
        $invoice = (new Invoice())
            ->setOutTradeNo('PAY-MOCK')
            ->setAmount(1200)
            ->setCurrency('CNY');
        $gateway = new MockGateway();

        $paying = $gateway->pay($invoice, 1200);
        self::assertSame(Invoice::STATUS_PAYING, $paying->status);
        self::assertSame('/mock/pay/PAY-MOCK', $paying->payUrl);

        $paid = $gateway->pay($invoice, 1200, ['autoPaid' => true, 'transactionId' => 'txn-1']);
        self::assertSame(Invoice::STATUS_PAID, $paid->status);
        self::assertSame('txn-1', $paid->payload['transactionId']);

        $deducted = $gateway->pay($invoice, 700);
        self::assertSame(700, $deducted->payload['amount']);

        $request = new Request([], [], [], [], [], [], json_encode([
            'secret' => 'mock',
            'outTradeNo' => 'PAY-MOCK',
            'amount' => 1200,
            'currency' => 'CNY',
            'transactionId' => 'txn-2',
        ], JSON_THROW_ON_ERROR));
        $notify = $gateway->notify($request);
        self::assertSame(Invoice::STATUS_PAID, $notify->status);
        self::assertSame('PAY-MOCK', $notify->outTradeNo);
        self::assertSame('txn-2', $notify->transactionId);

        $refund = $gateway->refund($invoice, 500, 1200, 'test');
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $refund->status);
        self::assertSame(Invoice::STATUS_REFUNDED, $gateway->refund($invoice, 700, 700, 'deducted')->status);
        self::assertStringStartsWith('mock-refund-', $refund->refundId);
        self::assertSame('SUCCESS', $gateway->getNotifySuccessResponse($notify)->getContent());
    }

    public function testNotifyRejectsInvalidSecret(): void
    {
        $this->expectException(PaymentVerificationException::class);
        (new MockGateway())->notify(new Request([], [], [], [], [], [], '{"secret":"bad"}'));
    }
}
