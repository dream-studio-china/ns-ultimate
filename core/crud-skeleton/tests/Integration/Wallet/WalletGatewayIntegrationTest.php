<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;

final class WalletGatewayIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $invoiceService;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoiceService = static::getContainer()->get(InvoiceServiceInterface::class);
    }

    public function testWalletPaymentAndRefund(): void
    {
        [$payer, $payerWallet] = $this->createUserWallet('wallet-payer@example.com', 5000);
        [, $systemWallet] = $this->createUserWallet('wallet-system@example.com', 0);

        $invoice = $this->invoiceService->createInvoice(new CreateInvoiceRequest(
            sourceType: 'wallet_test',
            sourceId: 'wallet-1',
            scene: Invoice::SCENE_ORDER,
            amount: 1500,
            currency: 'CNY',
            payer: $payer,
            subject: 'Wallet pay',
        ));

        $pay = $this->invoiceService->pay($invoice, Invoice::PAYMENT_WALLET, ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(Invoice::STATUS_PAID, $pay->status);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertNotNull($invoice->getTransactionId());

        $this->em->clear();
        $payerWallet = $this->em->getRepository(Wallet::class)->find($payerWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(3500, $payerWallet->getBalance());
        self::assertSame(1500, $systemWallet->getBalance());

        $invoice = $this->em->getRepository(Invoice::class)->find($invoice->getId());
        $refund = $this->invoiceService->refund($invoice, 1500, 'wallet refund', ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);

        $this->em->clear();
        $payerWallet = $this->em->getRepository(Wallet::class)->find($payerWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(5000, $payerWallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    public function testWalletGatewayRequiresSystemWallet(): void
    {
        [$payer] = $this->createUserWallet('wallet-error@example.com', 1000);
        $invoice = $this->invoiceService->createInvoice(new CreateInvoiceRequest('wallet_test', 'wallet-2', Invoice::SCENE_ORDER, 100, 'CNY', $payer));

        $this->expectException(\InvalidArgumentException::class);
        $this->invoiceService->pay($invoice, Invoice::PAYMENT_WALLET);
    }

    public function testWalletGatewayRejectsExternalNotify(): void
    {
        $gateway = static::getContainer()->get(PaymentGatewayRegistry::class)->get(Invoice::PAYMENT_WALLET);

        $this->expectException(\App\Payment\Exception\PaymentVerificationException::class);
        $gateway->notify(new \Symfony\Component\HttpFoundation\Request());
    }

    public function testGetNotifySuccessResponseReturnsTextResponse(): void
    {
        $gateway = static::getContainer()->get(PaymentGatewayRegistry::class)->get(Invoice::PAYMENT_WALLET);
        $result = new \App\Payment\DTO\PaymentNotifyResult(
            payment: Invoice::PAYMENT_WALLET,
            outTradeNo: 'TEST001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: 'OK',
        );

        $response = $gateway->getNotifySuccessResponse($result);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
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

        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', [
            'balance' => $balance,
            'id' => $wallet->getId(),
        ]);
        $this->em->refresh($wallet);

        return [$user, $wallet];
    }
}
