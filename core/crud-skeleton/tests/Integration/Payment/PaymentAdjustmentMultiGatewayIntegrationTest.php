<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Exception\InvoiceInvalidTransitionException;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Comprehensive integration tests covering wallet deduction + all three gateways:
 * - mock  (MockGateway)
 * - wallet (WalletGateway)
 * - wechat (WechatPayGateway)
 *
 * Each gateway is tested standalone and combined with wallet deduction.
 */
final class PaymentAdjustmentMultiGatewayIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $invoiceService;
    private PaymentGatewayRegistry $gatewayRegistry;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoiceService = static::getContainer()->get(InvoiceServiceInterface::class);
        $this->gatewayRegistry = static::getContainer()->get(PaymentGatewayRegistry::class);
    }

    // ========================================================================
    // Mock Gateway — standalone and with deduction
    // ========================================================================

    public function testMockGatewayStandalonePayAndRefund(): void
    {
        $payer = $this->createUser('mock-standalone@example.com');
        $invoice = $this->createInvoice($payer, 1000);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);

        $paid = $this->invoiceService->markPaid($invoice, new PaymentNotifyResult(
            Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 1000, 'CNY',
        ));
        self::assertSame(Invoice::STATUS_PAID, $paid->getStatus());

        $refund = $this->invoiceService->refund($invoice, 400, 'partial mock refund');
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $refund->status);
        self::assertSame(400, $invoice->getRefundedAmount());

        $refund2 = $this->invoiceService->refund($invoice, 600, 'final mock refund');
        self::assertSame(Invoice::STATUS_REFUNDED, $refund2->status);
        self::assertSame(1000, $invoice->getRefundedAmount());
    }

    public function testMockGatewayWithAutoPaidSucceedsImmediately(): void
    {
        $payer = $this->createUser('mock-autopaid@example.com');
        $invoice = $this->createInvoice($payer, 500);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    public function testMockGatewayWithWalletDeductionAndAutoPaid(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('mock-deduct-autopaid@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('mock-deduct-autopaid-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1500);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 500,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::PAYMENT_MOCK, $invoice->getPayment());

        $this->em->refresh($payerWallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1500, $payerWallet->getBalance());
        self::assertSame(500, $systemWallet->getBalance());

        $refund = $this->invoiceService->refund($invoice, 1500, 'full refund');
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);

        $this->em->refresh($payerWallet);
        self::assertSame(2000, $payerWallet->getBalance());
    }

    public function testMockGatewayNotifyConfirmsDeductedInvoice(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('mock-deduct-notify@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('mock-deduct-notify-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
        self::assertSame(1700, $this->refreshBalance($payerWallet));
        self::assertSame(300, $this->refreshBalance($systemWallet));

        $paid = $this->invoiceService->handleNotifyResult(new PaymentNotifyResult(
            Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 700, 'CNY',
        ));
        self::assertSame(Invoice::STATUS_PAID, $paid->getStatus());

        $refund = $this->invoiceService->refund($invoice, 1000, 'refund');
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);

        self::assertSame(2000, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    public function testMockGatewayNotifyAmountMismatchWithDeductionRejected(): void
    {
        [$payer] = $this->createUserWallet('mock-deduct-mismatch@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('mock-deduct-mismatch-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        $this->expectException(InvoiceAmountMismatchException::class);
        $this->invoiceService->handleNotifyResult(new PaymentNotifyResult(
            Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 1000, 'CNY',
        ));
    }

    // ========================================================================
    // Wallet Gateway — standalone and with deduction
    // ========================================================================

    public function testWalletGatewayStandalonePayAndRefund(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('wallet-standalone@example.com', 5000);
        [, $systemWallet] = $this->createUserWallet('wallet-standalone-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1500);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_WALLET, [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::PAYMENT_WALLET, $invoice->getPayment());

        self::assertSame(3500, $this->refreshBalance($payerWallet));
        self::assertSame(1500, $this->refreshBalance($systemWallet));

        $refund = $this->invoiceService->refund($invoice, 1500, 'wallet refund', [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);

        self::assertSame(5000, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    public function testWalletGatewayPartialRefund(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('wallet-partial@example.com', 3000);
        [, $systemWallet] = $this->createUserWallet('wallet-partial-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_WALLET, ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(2000, $this->refreshBalance($payerWallet));

        $refund = $this->invoiceService->refund($invoice, 300, 'partial wallet refund', [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $refund->status);
        self::assertSame(2300, $this->refreshBalance($payerWallet));

        $refund2 = $this->invoiceService->refund($invoice, 700, 'final wallet refund', [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_REFUNDED, $refund2->status);
        self::assertSame(3000, $this->refreshBalance($payerWallet));
    }

    public function testWalletGatewayWithWalletDeductionMeansFullWalletPayment(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('wallet-deduct@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('wallet-deduct-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 400);

        // wallet amount == invoice amount → gatewayAmount == 0 → effective payment = wallet
        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 400,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(Invoice::PAYMENT_WALLET, $invoice->getPayment());
        self::assertSame(0, $result->payload['gatewayAmount']);

        self::assertSame(600, $this->refreshBalance($payerWallet));
        self::assertSame(400, $this->refreshBalance($systemWallet));

        $this->invoiceService->refund($invoice, 400, 'refund');
        self::assertSame(1000, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    // ========================================================================
    // Wechat Gateway — standalone (integration through registry)
    // ========================================================================

    public function testWechatGatewayIsRegistered(): void
    {
        self::assertTrue($this->gatewayRegistry->has(Invoice::PAYMENT_WECHAT));
        $gateway = $this->gatewayRegistry->get(Invoice::PAYMENT_WECHAT);
        self::assertSame(Invoice::PAYMENT_WECHAT, $gateway::getName());
    }

    public function testWechatGatewayPayThrowsForUnsupportedTradeType(): void
    {
        $payer = $this->createUser('wechat-unsupported@example.com');
        $invoice = $this->createInvoice($payer, 500);

        $gateway = $this->gatewayRegistry->get(Invoice::PAYMENT_WECHAT);
        $invoice->setTradeType('unsupported');

        $this->expectException(\InvalidArgumentException::class);
        $gateway->pay($invoice, 500);
    }

    public function testWechatGatewayPayJsapiRequiresWechatUser(): void
    {
        $payer = $this->createUser('wechat-jsapi@example.com');
        $invoice = $this->createInvoice($payer, 500);
        $invoice->setTradeType('jsapi');

        $gateway = $this->gatewayRegistry->get(Invoice::PAYMENT_WECHAT);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WeChat user not found');
        $gateway->pay($invoice, 500);
    }

    // ========================================================================
    // Cross-gateway scenarios
    // ========================================================================

    #[Group('low-value')]
    public function testAllThreeGatewayNamesAreKnown(): void
    {
        $names = $this->gatewayRegistry->names();
        self::assertContains(Invoice::PAYMENT_MOCK, $names);
        self::assertContains(Invoice::PAYMENT_WALLET, $names);
        self::assertContains(Invoice::PAYMENT_WECHAT, $names);
    }

    public function testDeductedInvoiceWithMockReleasesOnGatewayFailure(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('cross-fail@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('cross-fail-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        try {
            $this->invoiceService->pay($invoice, 'nonexistent-gateway', [
                'walletAmount' => 400,
                'systemWalletId' => $systemWallet->getId(),
            ]);
            self::fail('Expected missing gateway exception.');
        } catch (\Throwable) {
        }

        self::assertSame(2000, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    public function testDeductedInvoiceCancelReleasesDeduction(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('cancel-deduct@example.com', 1500);
        [, $systemWallet] = $this->createUserWallet('cancel-deduct-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 800);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        self::assertSame(1200, $this->refreshBalance($payerWallet));
        self::assertSame(300, $this->refreshBalance($systemWallet));

        $this->invoiceService->cancel($invoice);

        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
        self::assertSame(1500, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    public function testDeductedInvoiceMarkFailedReleasesDeduction(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('failed-deduct@example.com', 1500);
        [, $systemWallet] = $this->createUserWallet('failed-deduct-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 800);

        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        self::assertSame(1200, $this->refreshBalance($payerWallet));
        self::assertSame(300, $this->refreshBalance($systemWallet));

        $this->invoiceService->markFailed($invoice, new PaymentNotifyResult(
            Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_FAILED, 500,
        ));

        self::assertSame(Invoice::STATUS_FAILED, $invoice->getStatus());
        self::assertSame(1500, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    public function testSequentialMultipleInvoicesWithDifferentGateways(): void
    {
        $payer = $this->createUser('seq-payer@example.com');
        $wallet = $this->createWalletForUser($payer, 10000);

        // System wallet gets balance from all deductions
        $systemUser = $this->createUser('seq-system@example.com');
        $systemWallet = $this->createWalletForUser($systemUser, 0);

        // Invoice 1: mock only, no deduction
        $inv1 = $this->createInvoice($payer, 2000);
        $r1 = $this->invoiceService->pay($inv1, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);
        self::assertSame(Invoice::STATUS_PAID, $r1->status);
        self::assertSame(Invoice::PAYMENT_MOCK, $inv1->getPayment());

        // Invoice 2: mock + wallet deduction
        $inv2 = $this->createInvoice($payer, 2000);
        $r2 = $this->invoiceService->pay($inv2, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 500,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);
        self::assertSame(Invoice::STATUS_PAID, $r2->status);

        // Invoice 3: wallet only
        $inv3 = $this->createInvoice($payer, 1500);
        $r3 = $this->invoiceService->pay($inv3, Invoice::PAYMENT_WALLET, [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_PAID, $r3->status);

        // Invoice 4: full wallet deduction (no gateway)
        $inv4 = $this->createInvoice($payer, 1000);
        $r4 = $this->invoiceService->pay($inv4, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 1000,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Invoice::STATUS_PAID, $r4->status);
        self::assertSame(Invoice::PAYMENT_WALLET, $inv4->getPayment());

        // Verify final wallet balance:
        // Started: 10000
        // inv2 deduction: -500
        // inv3 wallet pay: -1500
        // inv4 deduction: -1000
        // Remaining: 10000 - 500 - 1500 - 1000 = 7000
        self::assertSame(7000, $this->refreshBalance($wallet));

        // System wallet should have: 500 + 1500 + 1000 = 3000
        self::assertSame(3000, $this->refreshBalance($systemWallet));

        // Refund inv2 and inv4
        $this->invoiceService->refund($inv2, 2000, 'r');
        $this->invoiceService->refund($inv4, 1000, 'r');

        self::assertSame(8500, $this->refreshBalance($wallet));
        self::assertSame(1500, $this->refreshBalance($systemWallet));
    }

    #[Group('low-value')]
    public function testDeductionIsIdempotentOnReapply(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('idem-deduct@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('idem-deduct-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        // Apply deduction and pay
        $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'walletAmount' => 300,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);

        self::assertSame(1700, $this->refreshBalance($payerWallet));

        // Duplicate notification (simulate retry callback) should be idempotent
        $duplicate = $this->invoiceService->handleNotifyResult(new PaymentNotifyResult(
            Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 700, 'CNY',
        ));
        self::assertSame(Invoice::STATUS_PAID, $duplicate->getStatus());

        // Balance should NOT be deducted twice
        self::assertSame(1700, $this->refreshBalance($payerWallet));
        self::assertSame(300, $this->refreshBalance($systemWallet));
    }

    public function testWalletGatewayRejectsWithoutSystemWalletId(): void
    {
        [$payer] = $this->createUserWallet('wallet-no-sys@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->invoiceService->pay($invoice, Invoice::PAYMENT_WALLET);
    }

    // ========================================================================
    // Structured deduction payload
    // ========================================================================

    public function testStructuredDeductionPayloadWithMockGateway(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('struct-deduct@example.com', 2000);
        [, $systemWallet] = $this->createUserWallet('struct-deduct-sys@example.com', 0);
        $invoice = $this->createInvoice($payer, 1000);

        $result = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, [
            'deduction' => [
                'type' => 'wallet_balance',
                'amount' => 400,
                'currency' => 'CNY',
            ],
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(1600, $this->refreshBalance($payerWallet));
        self::assertSame(400, $this->refreshBalance($systemWallet));

        $this->invoiceService->refund($invoice, 1000, 'refund');
        self::assertSame(2000, $this->refreshBalance($payerWallet));
        self::assertSame(0, $this->refreshBalance($systemWallet));
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createInvoice(User $payer, int $amount): Invoice
    {
        return $this->invoiceService->createInvoice(new CreateInvoiceRequest(
            'multigateway_test', uniqid('src-', true), Invoice::SCENE_ORDER, $amount, 'CNY', $payer,
        ));
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword('password');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array{User, Wallet} */
    private function createUserWallet(string $email, int $balance): array
    {
        $user = $this->createUser($email);
        $wallet = $this->createWalletForUser($user, $balance);

        return [$user, $wallet];
    }

    private function createWalletForUser(User $user, int $balance): Wallet
    {
        $wallet = new Wallet($user, 'CNY');
        $this->em->persist($wallet);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE wallet SET balance = :balance WHERE id = :id',
            ['balance' => $balance, 'id' => $wallet->getId()],
        );
        $this->em->refresh($wallet);

        return $wallet;
    }

    private function refreshBalance(Wallet $wallet): int
    {
        $this->em->refresh($wallet);
        return $wallet->getBalance();
    }
}
