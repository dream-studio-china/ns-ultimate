<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\Payment\InvoiceDepositRefundGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceDepositRefundGuardTest extends TestCase
{
    private InvoiceServiceInterface $inner;
    private VoucherRepository $voucherRepository;
    private InvoiceDepositRefundGuard $guard;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(InvoiceServiceInterface::class);
        $this->voucherRepository = $this->createMock(VoucherRepository::class);
        $this->guard = new InvoiceDepositRefundGuard($this->inner, $this->voucherRepository);
    }

    private function createInvoice(): Invoice
    {
        $user = new User();
        $user->setEmail('t@t.com')->setUsername('t');

        return (new Invoice())
            ->setSourceType('wallet_topup')
            ->setSourceId('src-1')
            ->setScene(Invoice::SCENE_DEPOSIT)
            ->setStatus(Invoice::STATUS_PAID)
            ->setAmount(50000)
            ->setCurrency('CNY')
            ->setPayer($user);
    }

    private function createVoucher(string $invoiceUuid, string $status): Voucher
    {
        $user = new User();
        $user->setEmail('t@t.com')->setUsername('t');
        $wallet = new Wallet($user, 'CNY');

        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_INVOICE,
            $invoiceUuid,
            50000,
            'CNY',
            'ref-' . $invoiceUuid,
            'system',
        );
        if ($status === Voucher::STATUS_APPLIED) {
            $voucher->markApplied('tx-' . $invoiceUuid);
        }

        return $voucher;
    }

    public function testRefundBlockedWhileVoucherApplied(): void
    {
        $invoice = $this->createInvoice();
        $this->voucherRepository->method('findByVoucherSource')
            ->with(Voucher::VOUCHER_TYPE_INVOICE, $invoice->getUuid())
            ->willReturn($this->createVoucher($invoice->getUuid(), Voucher::STATUS_APPLIED));
        $this->inner->expects(self::never())->method('refund');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reverse the deposit before refunding');
        $this->guard->refund($invoice, 50000, 'refund', []);
    }

    public function testRefundDelegatesWhenVoucherReversed(): void
    {
        $invoice = $this->createInvoice();
        $this->voucherRepository->method('findByVoucherSource')
            ->with(Voucher::VOUCHER_TYPE_INVOICE, $invoice->getUuid())
            ->willReturn($this->createVoucher($invoice->getUuid(), Voucher::STATUS_REVERSED));
        $this->inner->expects(self::once())->method('refund')
            ->with($invoice, 50000, 'refund', [])
            ->willThrowException(new \RuntimeException('delegated'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('delegated');
        $this->guard->refund($invoice, 50000, 'refund', []);
    }

    public function testRefundDelegatesWhenNoVoucher(): void
    {
        $invoice = $this->createInvoice();
        $this->voucherRepository->method('findByVoucherSource')
            ->with(Voucher::VOUCHER_TYPE_INVOICE, $invoice->getUuid())
            ->willReturn(null);
        $this->inner->expects(self::once())->method('refund')
            ->with($invoice, 50000, 'refund', [])
            ->willThrowException(new \RuntimeException('delegated'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('delegated');
        $this->guard->refund($invoice, 50000, 'refund', []);
    }

    public function testCreateInvoiceDelegates(): void
    {
        $request = new CreateInvoiceRequest('wallet_topup', 'src-1', Invoice::SCENE_DEPOSIT, 50000, 'CNY');
        $expected = $this->createInvoice();
        $this->inner->expects(self::once())->method('createInvoice')
            ->with($request)->willReturn($expected);

        self::assertSame($expected, $this->guard->createInvoice($request));
    }
}