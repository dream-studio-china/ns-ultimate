<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Deposit;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Payment\Repository\InvoiceRepository;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Service\Deposit\InvoiceDepositProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceDepositProviderTest extends TestCase
{
    private InvoiceRepository $invoiceRepository;
    private InvoiceDepositProvider $provider;

    protected function setUp(): void
    {
        $this->invoiceRepository = $this->createMock(InvoiceRepository::class);
        $this->provider = new InvoiceDepositProvider(
            $this->invoiceRepository,
            $this->createMock(EntityManagerInterface::class),
        );
    }

    private function createUser(?int $id = null): User
    {
        $user = new User();
        $user->setEmail('t@t.com')->setUsername('t');
        if ($id !== null) {
            $r = new \ReflectionProperty(User::class, 'id');
            $r->setValue($user, $id);
        }
        return $user;
    }

    private function createVoucher(Wallet $wallet, string $invoiceUuid, int $amount = 50000, string $currency = 'CNY'): Voucher
    {
        return new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_INVOICE,
            $invoiceUuid,
            $amount,
            $currency,
            'ref-inv-' . $invoiceUuid,
            'system',
        );
    }

    private function createPaidInvoice(User $payer, int $amount = 50000, string $currency = 'CNY'): Invoice
    {
        return (new Invoice())
            ->setSourceType('wallet_topup')
            ->setSourceId('src-' . $amount)
            ->setScene(Invoice::SCENE_DEPOSIT)
            ->setStatus(Invoice::STATUS_PAID)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setPayer($payer);
    }

    public function testNameAndSupports(): void
    {
        self::assertSame(Voucher::VOUCHER_TYPE_INVOICE, InvoiceDepositProvider::getName());
        self::assertTrue($this->provider->supports(Voucher::VOUCHER_TYPE_INVOICE));
        self::assertFalse($this->provider->supports(Voucher::VOUCHER_TYPE_MANUAL));
        self::assertFalse($this->provider->supports('other'));
    }

    public function testAssertPermittedIsOpen(): void
    {
        $this->provider->assertPermitted();
        $this->provider->assertPermitted(['userId' => 7]);

        self::assertTrue(true);
    }

    public function testAuthorizeHappyPathWritesDepositHint(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner);

        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);
        $voucher = $this->createVoucher($wallet, $invoice->getUuid());

        $this->provider->authorize($voucher, []);

        $hint = $invoice->getExtraData()['wallet_deposit'] ?? null;
        self::assertIsArray($hint);
        self::assertSame('deposited', $hint['status']);
        self::assertSame($voucher->getUuid(), $hint['voucherUuid']);
        self::assertArrayHasKey('depositedAt', $hint);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    public function testAuthorizeRejectsMissingInvoice(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => 'missing'])->willReturn(null);
        $voucher = $this->createVoucher($wallet, 'missing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deposit invoice missing not found.');
        $this->provider->authorize($voucher, []);
    }

    public function testAuthorizeRejectsUnpaidInvoice(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner)->setStatus(Invoice::STATUS_PENDING);
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not paid');
        $this->provider->authorize($this->createVoucher($wallet, $invoice->getUuid()), []);
    }

    public function testAuthorizeRejectsAmountMismatch(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner, amount: 40000);
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match deposit amount');
        $this->provider->authorize($this->createVoucher($wallet, $invoice->getUuid()), []);
    }

    public function testAuthorizeRejectsCurrencyMismatch(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner, currency: 'USD');
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match deposit currency');
        $this->provider->authorize($this->createVoucher($wallet, $invoice->getUuid()), []);
    }

    public function testAuthorizeRejectsMissingPayer(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner);
        $invoice->setPayer(null);
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must own the target wallet');
        $this->provider->authorize($this->createVoucher($wallet, $invoice->getUuid()), []);
    }

    public function testAuthorizeRejectsPayerMismatch(): void
    {
        $owner = $this->createUser(10);
        $other = $this->createUser(99);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($other);
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must own the target wallet');
        $this->provider->authorize($this->createVoucher($wallet, $invoice->getUuid()), []);
    }

    public function testReverseWritesRevertedHint(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $invoice = $this->createPaidInvoice($owner);
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);
        $voucher = $this->createVoucher($wallet, $invoice->getUuid());

        $this->provider->reverse($voucher, 'withdrawn');

        $hint = $invoice->getExtraData()['wallet_deposit'] ?? null;
        self::assertIsArray($hint);
        self::assertSame('reverted', $hint['status']);
        self::assertArrayHasKey('revertedAt', $hint);
    }

    public function testReverseIgnoresMissingInvoice(): void
    {
        $owner = $this->createUser(10);
        $wallet = new Wallet($owner, 'CNY');
        $this->invoiceRepository->method('findOneBy')->with(['uuid' => 'gone'])->willReturn(null);

        $this->provider->reverse($this->createVoucher($wallet, 'gone'), 'withdrawn');

        self::assertTrue(true);
    }
}