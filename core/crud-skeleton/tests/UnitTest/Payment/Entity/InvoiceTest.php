<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Payment\Entity;

use App\Payment\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testDefaultsAndAccessors(): void
    {
        $invoice = new Invoice();

        self::assertNull($invoice->getId());
        self::assertNotSame('', $invoice->getUuid());
        self::assertStringStartsWith('PAY', $invoice->getOutTradeNo());
        self::assertSame(Invoice::STATUS_PENDING, $invoice->getStatus());
        self::assertSame(0, $invoice->getAmount());
        self::assertSame(0.0, $invoice->getAmountAsFloat());
        self::assertSame(0, $invoice->getRefundedAmount());
        self::assertSame('CNY', $invoice->getCurrency());
        self::assertInstanceOf(\DateTimeImmutable::class, $invoice->getCreatedAt());
        self::assertNull($invoice->getUpdatedAt());

        $paidAt = new \DateTimeImmutable('-1 hour');
        $cancelledAt = new \DateTimeImmutable('-30 minutes');
        $refundedAt = new \DateTimeImmutable('-5 minutes');

        $invoice->setOutTradeNo('PAY-1')
            ->setTransactionId('txn-1')
            ->setSourceType('trade_order')
            ->setSourceId('order-uuid')
            ->setScene(Invoice::SCENE_ORDER)
            ->setPayment(Invoice::PAYMENT_MOCK)
            ->setGateway('online')
            ->setTradeType('h5')
            ->setStatus(Invoice::STATUS_PAID)
            ->setAmount(1234)
            ->setRefundedAmount(234)
            ->setCurrency('usd')
            ->setSubject('Subject')
            ->setDescription('Description')
            ->setExtraData(['a' => 1])
            ->setPaidAt($paidAt)
            ->setCancelledAt($cancelledAt)
            ->setRefundedAt($refundedAt)
            ->appendExtraData('notify', ['ok' => true]);

        self::assertSame('PAY-1', $invoice->getOutTradeNo());
        self::assertSame('txn-1', $invoice->getTransactionId());
        self::assertSame('trade_order', $invoice->getSourceType());
        self::assertSame('order-uuid', $invoice->getSourceId());
        self::assertSame(Invoice::SCENE_ORDER, $invoice->getScene());
        self::assertSame(Invoice::PAYMENT_MOCK, $invoice->getPayment());
        self::assertSame('online', $invoice->getGateway());
        self::assertSame('h5', $invoice->getTradeType());
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame(1234, $invoice->getAmount());
        self::assertSame(12.34, $invoice->getAmountAsFloat());
        self::assertSame(234, $invoice->getRefundedAmount());
        self::assertSame('USD', $invoice->getCurrency());
        self::assertSame('Subject', $invoice->getSubject());
        self::assertSame('Description', $invoice->getDescription());
        self::assertSame(['a' => 1, 'notify' => ['ok' => true]], $invoice->getExtraData());
        self::assertSame($paidAt, $invoice->getPaidAt());
        self::assertSame($cancelledAt, $invoice->getCancelledAt());
        self::assertSame($refundedAt, $invoice->getRefundedAt());
        self::assertStringContainsString('PAY-1', (string) $invoice);

        $invoice->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $invoice->getCreatedAt());
    }
}
