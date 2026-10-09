<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Entity;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\PaymentDeduction;
use PHPUnit\Framework\TestCase;

final class PaymentDeductionTest extends TestCase
{
    public function testDefaultsAndStateMarkers(): void
    {
        [$invoice, $wallet] = self::invoiceAndWallet();
        $deduction = new PaymentDeduction($invoice, $wallet, 2, 300, 'cny', 'ref-1');

        self::assertNull($deduction->getId());
        self::assertNotSame('', $deduction->getUuid());
        self::assertSame($invoice->getUuid(), $deduction->getInvoiceId());
        self::assertSame($invoice->getOutTradeNo(), $deduction->getInvoiceNo());
        self::assertSame(1, $deduction->getPayerId());
        self::assertSame($wallet, $deduction->getWallet());
        self::assertSame(2, $deduction->getSystemWalletId());
        self::assertSame(PaymentDeduction::TYPE_WALLET_BALANCE, $deduction->getType());
        self::assertSame(300, $deduction->getAmount());
        self::assertSame('CNY', $deduction->getCurrency());
        self::assertSame(PaymentDeduction::STATUS_PENDING, $deduction->getStatus());
        self::assertSame('ref-1', $deduction->getReferenceId());
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        $deduction->markApplied('tx-1', ['fromWalletId' => 1, 'toWalletId' => 2]);
        self::assertSame(PaymentDeduction::STATUS_APPLIED, $deduction->getStatus());
        self::assertSame('tx-1', $deduction->getTransactionId());
        self::assertSame(2, $deduction->getMetadata()['toWalletId']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getAppliedAt());

        $deduction->markReleased('tx-2', 'cancelled');
        self::assertSame(PaymentDeduction::STATUS_RELEASED, $deduction->getStatus());
        self::assertSame('tx-2', $deduction->getReversalTransactionId());
        self::assertSame('cancelled', $deduction->getMetadata()['releaseReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getReleasedAt());

        $deduction->markRefunded('tx-3', 'refund');
        self::assertSame(PaymentDeduction::STATUS_REFUNDED, $deduction->getStatus());
        self::assertSame('tx-3', $deduction->getReversalTransactionId());
        self::assertSame('refund', $deduction->getMetadata()['refundReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getRefundedAt());

        $deduction->markFailed('failed');
        self::assertSame(PaymentDeduction::STATUS_FAILED, $deduction->getStatus());
        self::assertSame('failed', $deduction->getMetadata()['failedReason']);

        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        \Closure::bind(function (): void {
            unset($this->createdAt);
        }, $deduction, PaymentDeduction::class)();
        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());
    }

    public function testRequiresInvoicePayer(): void
    {
        [, $wallet] = self::invoiceAndWallet();

        $this->expectException(\InvalidArgumentException::class);
        new PaymentDeduction(new Invoice(), $wallet, 2, 300, 'CNY', 'ref-1');
    }

    /** @return array{Invoice, Wallet} */
    private static function invoiceAndWallet(): array
    {
        $user = new User();
        self::setId($user, 1);
        $invoice = (new Invoice())->setAmount(1000)->setCurrency('cny')->setPayer($user);
        $wallet = new Wallet($user, 'CNY');
        self::setId($wallet, 1);

        return [$invoice, $wallet];
    }

    private static function setId(object $object, int $id): void
    {
        $property = new \ReflectionProperty($object, 'id');
        $property->setValue($object, $id);
    }
}
