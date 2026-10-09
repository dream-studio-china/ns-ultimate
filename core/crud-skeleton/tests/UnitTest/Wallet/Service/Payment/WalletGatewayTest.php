<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Payment\WalletGateway;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WalletGatewayTest extends TestCase
{
    #[Group('low-value')]
    public function testGetNameAndGetNotifySuccessResponse(): void
    {
        $gateway = new WalletGateway($this->createMock(WalletRepository::class), $this->createMock(TransferServiceInterface::class));

        self::assertSame(Invoice::PAYMENT_WALLET, $gateway::getName());

        $response = $gateway->getNotifySuccessResponse(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_WALLET,
            outTradeNo: 'TEST',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: 'OK',
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    #[Group('low-value')]
    public function testNotifyRejectsExternalCallbacks(): void
    {
        $gateway = new WalletGateway($this->createMock(WalletRepository::class), $this->createMock(TransferServiceInterface::class));

        $this->expectException(PaymentVerificationException::class);
        $gateway->notify(new Request());
    }

    public function testPayRequiresPayer(): void
    {
        $gateway = new WalletGateway($this->createMock(WalletRepository::class), $this->createMock(TransferServiceInterface::class), 1);
        $invoice = new Invoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice has no payer for wallet payment.');
        $gateway->pay($invoice, 100);
    }

    public function testPayRequiresWalletForPayer(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn(null);

        $gateway = new WalletGateway($walletRepo, $this->createMock(TransferServiceInterface::class), 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No CNY wallet found for payer.');
        $gateway->pay($invoice, 100);
    }

    public function testRefundRequiresPayer(): void
    {
        $gateway = new WalletGateway($this->createMock(WalletRepository::class), $this->createMock(TransferServiceInterface::class), 1);
        $invoice = new Invoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice has no payer for wallet refund.');
        $gateway->refund($invoice, 100, 100, 'test');
    }

    public function testRefundRequiresWalletForPayer(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn(null);

        $gateway = new WalletGateway($walletRepo, $this->createMock(TransferServiceInterface::class), 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No CNY wallet found for payer.');
        $gateway->refund($invoice, 100, 100, 'test');
    }

    #[Group('low-value')]
    public function testPayRequiresSystemWalletId(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $wallet = new Wallet($payer, 'CNY');
        $walletRef = new \ReflectionProperty($wallet, 'id');
        $walletRef->setValue($wallet, 1);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn($wallet);

        $gateway = new WalletGateway($walletRepo, $this->createMock(TransferServiceInterface::class), null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('systemWalletId is required for wallet payment.');
        $gateway->pay($invoice, 100);
    }

    public function testRefundRequiresSystemWalletId(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $wallet = new Wallet($payer, 'CNY');
        $walletRef = new \ReflectionProperty($wallet, 'id');
        $walletRef->setValue($wallet, 1);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn($wallet);

        $gateway = new WalletGateway($walletRepo, $this->createMock(TransferServiceInterface::class), null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('systemWalletId is required for wallet refund.');
        $gateway->refund($invoice, 100, 100, 'test');
    }

    public function testRefundReturnsPartialRefundedStatus(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $wallet = new Wallet($payer, 'CNY');
        $walletRef = new \ReflectionProperty($wallet, 'id');
        $walletRef->setValue($wallet, 1);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn($wallet);

        $transaction = new \App\Wallet\Entity\Transaction('uuid-1', 50, 'transfer');
        $transferResult = new TransferResult($transaction, 50, 50);
        $transferService = $this->createMock(TransferServiceInterface::class);
        $transferService->method('transfer')->willReturn($transferResult);

        $gateway = new WalletGateway($walletRepo, $transferService, 1);
        $result = $gateway->refund($invoice, 50, 200, 'partial');

        self::assertInstanceOf(PaymentRefundResult::class, $result);
        self::assertSame(50, $result->amount);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $result->status);
    }

    #[Group('low-value')]
    public function testRefundReturnsRefundedStatus(): void
    {
        $payer = new \App\Identity\Entity\User();
        $payerRef = new \ReflectionProperty($payer, 'id');
        $payerRef->setValue($payer, 99);

        $wallet = new Wallet($payer, 'CNY');
        $walletRef = new \ReflectionProperty($wallet, 'id');
        $walletRef->setValue($wallet, 1);

        $invoice = (new Invoice())->setPayer($payer)->setCurrency('CNY');

        $walletRepo = $this->createMock(WalletRepository::class);
        $walletRepo->method('findByUserAndCurrency')->willReturn($wallet);

        $transaction = new \App\Wallet\Entity\Transaction('uuid-2', 200, 'transfer');
        $transferResult = new TransferResult($transaction, 200, 200);
        $transferService = $this->createMock(TransferServiceInterface::class);
        $transferService->method('transfer')->willReturn($transferResult);

        $gateway = new WalletGateway($walletRepo, $transferService, 1);
        $result = $gateway->refund($invoice, 200, 200, 'full');

        self::assertSame(Invoice::STATUS_REFUNDED, $result->status);
    }
}
