<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Event;


use PHPUnit\Framework\Attributes\Group;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class InvoiceEventTest extends TestCase
{
    public function testEventPayloads(): void
    {
        $invoice = new Invoice();
        $notify = new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100);
        $refund = new PaymentRefundResult($invoice, 100, Invoice::STATUS_REFUNDED, 'refund-1');

        self::assertSame($invoice, (new InvoicePaidEvent($invoice, $notify))->getInvoice());
        self::assertSame($notify, (new InvoicePaidEvent($invoice, $notify))->getResult());
        self::assertSame($invoice, (new InvoiceFailedEvent($invoice, $notify))->getInvoice());
        self::assertSame($notify, (new InvoiceFailedEvent($invoice, $notify))->getResult());
        self::assertSame($invoice, (new InvoiceRefundedEvent($invoice, $refund))->getInvoice());
        self::assertSame($refund, (new InvoiceRefundedEvent($invoice, $refund))->getResult());
        self::assertSame($invoice, (new InvoiceCancelledEvent($invoice))->getInvoice());
    }
}
