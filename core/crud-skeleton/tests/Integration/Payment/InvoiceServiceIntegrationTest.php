<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Repository\InvoiceRepository;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $service;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(InvoiceServiceInterface::class);
    }

    public function testCreatePayNotifyRefundAndFindBySource(): void
    {
        $payer = $this->createUser('payer@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest(
            sourceType: 'trade_order',
            sourceId: 'order-1',
            scene: Invoice::SCENE_ORDER,
            amount: 1200,
            currency: 'CNY',
            payer: $payer,
            subject: 'Order 1',
            description: 'Test invoice',
            extraData: ['secret' => 'must-redact'],
        ));

        self::assertNotNull($invoice->getId());
        self::assertSame(Invoice::STATUS_PENDING, $invoice->getStatus());
        self::assertSame([$invoice], $this->service->findBySource('trade_order', 'order-1'));
        $repository = static::getContainer()->get(InvoiceRepository::class);
        self::assertSame($invoice, $repository->findOneByOutTradeNo($invoice->getOutTradeNo()));
        self::assertSame([$invoice], $repository->findBySource('trade_order', 'order-1'));

        $result = $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        $paid = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 1200,
            currency: 'CNY',
            transactionId: 'txn-service',
            rawData: ['signature' => 'secret-signature', 'ok' => true],
        ));
        self::assertSame(Invoice::STATUS_PAID, $paid->getStatus());
        self::assertSame('txn-service', $paid->getTransactionId());
        self::assertSame('[redacted]', $paid->getExtraData()['notify']['signature']);

        $duplicate = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 1200,
        ));
        self::assertSame($paid->getId(), $duplicate->getId());

        $partial = $this->service->refund($invoice, 500, 'partial');
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $partial->status);
        self::assertSame(1200, $partial->rawData['gateway']['paidAmount']);
        self::assertSame(500, $invoice->getRefundedAmount());

        $full = $this->service->refund($invoice, 700, 'full');
        self::assertSame(Invoice::STATUS_REFUNDED, $full->status);
        self::assertSame(1200, $invoice->getRefundedAmount());
        self::assertNotNull($invoice->getRefundedAt());
    }

    public function testCancelFailedNotifyAndValidationBranches(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-2', Invoice::SCENE_DEPOSIT, 100));
        $this->service->cancel($invoice, 'cancelled');
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
        self::assertSame('cancelled', $invoice->getExtraData()['cancel']['reason']);

        $failed = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-3', Invoice::SCENE_DEPOSIT, 100));
        $this->service->markFailed($failed, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $failed->getOutTradeNo(), Invoice::STATUS_FAILED, 100));
        self::assertSame(Invoice::STATUS_FAILED, $failed->getStatus());

        $mismatch = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-4', Invoice::SCENE_DEPOSIT, 100));
        $this->expectException(InvoiceAmountMismatchException::class);
        $this->service->markPaid($mismatch, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $mismatch->getOutTradeNo(), Invoice::STATUS_PAID, 99));
    }

    public function testGatewayRegistryFromContainer(): void
    {
        $registry = static::getContainer()->get(PaymentGatewayRegistry::class);
        self::assertTrue($registry->has(Invoice::PAYMENT_MOCK));
        self::assertTrue($registry->has(Invoice::PAYMENT_WALLET));
        self::assertTrue($registry->has(Invoice::PAYMENT_WECHAT));
        self::assertContains(Invoice::PAYMENT_MOCK, $registry->names());
        self::assertContains(Invoice::PAYMENT_WECHAT, $registry->names());
    }

    public function testHandleNotifyResultFailed(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-5', Invoice::SCENE_DEPOSIT, 100));
        $failed = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_FAILED,
            amount: 100,
            currency: 'CNY',
        ));
        self::assertSame(Invoice::STATUS_FAILED, $failed->getStatus());
    }

    public function testMarkPaidOnCancelledInvoiceThrows(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-6', Invoice::SCENE_DEPOSIT, 100));
        $this->service->cancel($invoice);
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());

        $this->expectException(\App\Payment\Exception\InvoiceInvalidTransitionException::class);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
    }

    public function testMarkFailedOnPaidInvoiceReturnsEarly(): void
    {
        $payer = $this->createUser('paid-failed@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-7', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));

        $result = $this->service->markFailed($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_FAILED, 100));
        self::assertSame(Invoice::STATUS_PAID, $result->getStatus());
    }

    public function testRefundRejectsNonPositiveAmount(): void
    {
        $payer = $this->createUser('refund-zero@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-8', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->refund($invoice, 0, 'zero');
    }

    public function testRefundRejectsInvalidInvoiceStatus(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-9', Invoice::SCENE_DEPOSIT, 100));

        $this->expectException(\App\Payment\Exception\InvoiceInvalidTransitionException::class);
        $this->service->refund($invoice, 50, 'pending');
    }

    public function testRefundRejectsAmountExceedingRemaining(): void
    {
        $payer = $this->createUser('refund-excess@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-10', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $this->service->refund($invoice, 30, 'partial');
        self::assertSame(30, $invoice->getRefundedAmount());

        $this->expectException(\App\Payment\Exception\InvoiceAmountMismatchException::class);
        $this->service->refund($invoice, 80, 'exceeds remaining');
    }

    public function testHandleNotifyResultRejectsUnsupportedStatus(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-11', Invoice::SCENE_DEPOSIT, 100));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: 'unknown',
            amount: 100,
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
}
