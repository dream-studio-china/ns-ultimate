<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Service\InvoiceServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Wallet;
use App\Wallet\Service\Payment\PaymentDeductionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class PaymentAdjustmentIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $invoiceService;
    private PaymentDeductionService $deductionService;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoiceService = static::getContainer()->get(InvoiceServiceInterface::class);
        $this->deductionService = static::getContainer()->get(PaymentDeductionService::class);
    }

    #[Group('low-value')]
    public function testWalletDeductionPlusMockPaymentAndFullRefund(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('deduct-pay@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('deduct-pay-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame(Invoice::PAYMENT_MOCK, $invoice->getPayment());
        self::assertSame(300, $this->deductionService->sumAppliedAmount($invoice));
        self::assertSame(700, $invoice->getExtraData()['pay']['amount']);

        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1700, $payerWallet->getBalance());
        self::assertSame(300, $systemWallet->getBalance());

        $refund = $this->invoiceService->refund($invoice, 1000, 'full refund');
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);
        self::assertSame(1000, $refund->amount);
        self::assertSame(700, $refund->rawData['gateway']['paidAmount']);
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());

        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(2000, $payerWallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    #[Group('low-value')]
    public function testNotifyMustMatchRemainingGatewayAmount(): void
    {
        [$payer] = $this->createUserWallet('deduct-notify@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('deduct-notify-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        $this->expectException(InvoiceAmountMismatchException::class);
        $this->invoiceService->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 1000,
            currency: 'CNY',
        ));
    }

    #[Group('low-value')]
    public function testFullWalletDeductionSetsPaymentWallet(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('deduct-full@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-full-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 600);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 600,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::PAYMENT_WALLET, $invoice->getPayment());
        self::assertSame(0, $result->payload['gatewayAmount']);

        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(400, $payerWallet->getBalance());
        self::assertSame(600, $systemWallet->getBalance());

        $this->invoiceService->refund($invoice, 600, 'refund wallet deduction');
        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1000, $payerWallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    #[Group('low-value')]
    public function testGatewayFailureReleasesDeduction(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('deduct-fail@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-fail-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 600);

        try {
            $this->invoiceService->pay($invoice, 'missing-gateway', [
                'walletAmount' => 200,
                'systemWalletId' => $systemWallet->getId(),
            ]);
            self::fail('Expected missing gateway exception.');
        } catch (\Throwable) {
        }

        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1000, $payerWallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
        $deduction = $this->deductionService->findApplied($invoice);
        self::assertNull($deduction);
    }

    public function testDeductedInvoiceRejectsPartialRefund(): void
    {
        [$payer] = $this->createUserWallet('deduct-partial-refund@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-partial-refund-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 600);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 200,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);

        $this->expectException(InvoiceAmountMismatchException::class);
        $this->invoiceService->refund($invoice, 200, 'partial rejected');
    }

    private function createInvoice(User $payer, int $amount): Invoice
    {
        return $this->invoiceService->createInvoice(new CreateInvoiceRequest('deduction_payment_test', uniqid('src-', true), Invoice::SCENE_ORDER, $amount, 'CNY', $payer));
    }

    /** @return array{User, Wallet} */
    private function createUserWallet(string $email, int $balance): array
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword('password');
        $user->setRoles(['ROLE_USER']);
        $wallet = new Wallet($user, 'CNY');
        $this->em->persist($user);
        $this->em->persist($wallet);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', ['balance' => $balance, 'id' => $wallet->getId()]);
        $this->em->refresh($wallet);

        return [$user, $wallet];
    }
}
