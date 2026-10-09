<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Deposit\DepositService;
use App\Wallet\Service\Deposit\DepositServiceInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end: a paid invoice funds a wallet deposit; while the deposit voucher
 * is APPLIED the invoice cannot be refunded; reversing the voucher re-opens the
 * refund path. The invoice lifecycle status stays `paid` throughout — "deposited"
 * is derived from the applied voucher, and only a JSON hint is written to
 * `extra_data`.
 */
final class InvoiceDepositLifecycleTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $invoiceService;
    private DepositServiceInterface $depositService;
    private WalletRepository $walletRepo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoiceService = static::getContainer()->get(InvoiceServiceInterface::class);
        $this->depositService = static::getContainer()->get(DepositService::class);
        $this->walletRepo = static::getContainer()->get(WalletRepository::class);

        foreach (['App\\Wallet\\Entity\\Voucher', 'App\\Wallet\\Entity\\Transaction'] as $table) {
            $this->em->createQuery("DELETE FROM $table")->execute();
        }
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(explode('@', $email)[0]);
        $user->setPassword('password');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testDepositLocksRefundUntilVoucherReversed(): void
    {
        $payer = $this->createUser('payer@example.com');
        $wallet = new Wallet($payer, 'CNY');
        $this->em->persist($wallet);
        $this->em->flush();

        $invoice = $this->invoiceService->createInvoice(new CreateInvoiceRequest(
            sourceType: 'wallet_topup',
            sourceId: 'topup-1',
            scene: Invoice::SCENE_DEPOSIT,
            amount: 50000,
            currency: 'CNY',
            payer: $payer,
        ));

        $paid = $this->invoiceService->pay($invoice, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);
        self::assertSame(Invoice::STATUS_PAID, $paid->status);

        $voucher = $this->depositService->deposit(
            Voucher::VOUCHER_TYPE_INVOICE,
            $invoice->getUuid(),
            $wallet->getId() ?? 0,
            50000,
            'CNY',
            'invoice-deposit-' . $invoice->getUuid(),
            'system',
            'Wallet topup from paid invoice',
        );
        self::assertSame(Voucher::STATUS_APPLIED, $voucher->getStatus());

        $this->em->refresh($invoice);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame('deposited', $invoice->getExtraData()['wallet_deposit']['status'] ?? null);
        self::assertSame(50000, $this->walletRepo->find($wallet->getId())?->getBalance());

        try {
            $this->invoiceService->refund($invoice, 50000, 'refund while deposited', []);
            self::fail('Expected refund to be blocked while the deposit voucher is applied.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reverse the deposit before refunding', $e->getMessage());
        }

        $this->depositService->reverse($voucher->getUuid(), 'withdraw the top-up');

        $this->em->refresh($invoice);
        self::assertSame('reverted', $invoice->getExtraData()['wallet_deposit']['status'] ?? null);
        self::assertSame(0, $this->walletRepo->find($wallet->getId())?->getBalance());

        $refund = $this->invoiceService->refund($invoice, 50000, 'refund after reversal', []);
        self::assertSame(Invoice::STATUS_REFUNDED, $refund->status);
    }
}