<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Payment;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Wallet\DTO\PaymentDeductionRequest;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\PaymentDeduction;
use App\Wallet\Repository\PaymentDeductionRepository;
use App\Wallet\Service\Payment\WalletBalanceAdjustmentProvider;
use App\Wallet\Service\Payment\PaymentDeductionServiceInterface;
use PHPUnit\Framework\TestCase;

final class WalletBalanceAdjustmentProviderTest extends TestCase
{
    public function testSupportsUsesDeductionRequestParsing(): void
    {
        $invoice = self::invoiceAndWallet()[0];
        $deductionService = $this->createMock(PaymentDeductionServiceInterface::class);
        $deductionService->method('createRequestFromOptions')
            ->willReturnOnConsecutiveCalls(
                new PaymentDeductionRequest(PaymentDeduction::TYPE_WALLET_BALANCE, 300, 'CNY'),
                null,
            );

        $provider = new WalletBalanceAdjustmentProvider($deductionService, $this->createMock(PaymentDeductionRepository::class));

        self::assertTrue($provider->supports($invoice, 'mock', ['walletAmount' => 300]));
        self::assertFalse($provider->supports($invoice, 'mock', []));
    }

    public function testApplyReturnsGenericAdjustmentResult(): void
    {
        $invoice = self::invoiceAndWallet()[0];
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $deductionService = $this->createMock(PaymentDeductionServiceInterface::class);
        $deductionService->method('applyFromOptions')->willReturn($deduction);

        $provider = new WalletBalanceAdjustmentProvider($deductionService, $this->createMock(PaymentDeductionRepository::class));
        $result = $provider->apply(new PaymentAdjustmentContext($invoice, 'mock', 1000, 'CNY', ['walletAmount' => 300]));

        self::assertSame(PaymentDeduction::TYPE_WALLET_BALANCE, $result->provider);
        self::assertSame(300, $result->amount);
        self::assertSame('CNY', $result->currency);
        self::assertSame('deduction-ref-1', $result->referenceId);
        self::assertSame('txn-1', $result->payload['transactionId']);
        self::assertSame(PaymentDeduction::STATUS_APPLIED, $result->payload['status']);
    }

    public function testApplyRejectsMissingDeductionRequest(): void
    {
        $deductionService = $this->createMock(PaymentDeductionServiceInterface::class);
        $deductionService->method('applyFromOptions')->willReturn(null);
        $provider = new WalletBalanceAdjustmentProvider($deductionService, $this->createMock(PaymentDeductionRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet balance deduction request is missing.');

        $provider->apply(new PaymentAdjustmentContext(new Invoice(), 'mock', 1000, 'CNY'));
    }

    public function testAppliedReturnsEmptyOrGenericAdjustmentResult(): void
    {
        $invoice = self::invoiceAndWallet()[0];
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $deductionService = $this->createMock(PaymentDeductionServiceInterface::class);
        $deductionService->method('findApplied')->willReturnOnConsecutiveCalls(null, $deduction);

        $provider = new WalletBalanceAdjustmentProvider($deductionService, $this->createMock(PaymentDeductionRepository::class));

        self::assertSame([], $provider->applied($invoice));
        self::assertSame(300, $provider->applied($invoice)[0]->amount);
    }

    public function testReleaseAndRefundReturnReversedAdjustmentResults(): void
    {
        $invoice = self::invoiceAndWallet()[0];
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $released = self::deduction($invoice)->markApplied('txn-1')->markReleased('release-txn-1', 'cancel');
        $refunded = self::deduction($invoice)->markApplied('txn-1')->markRefunded('refund-txn-1', 'refund');

        $deductionRepository = $this->createMock(PaymentDeductionRepository::class);
        $deductionRepository->method('findOneBy')->willReturn($deduction);
        $deductionService = $this->createMock(PaymentDeductionServiceInterface::class);
        $deductionService->method('release')->with($invoice, 'cancel')->willReturn($released);
        $deductionService->method('refund')->with($invoice, 'refund')->willReturn($refunded);

        $provider = new WalletBalanceAdjustmentProvider($deductionService, $deductionRepository);
        $adjustment = new PaymentAdjustmentResult(PaymentDeduction::TYPE_WALLET_BALANCE, 300, 'CNY', 'deduction-ref-1');

        $releaseResult = $provider->release($invoice, $adjustment, 'cancel');
        self::assertSame(PaymentDeduction::STATUS_RELEASED, $releaseResult->payload['status']);
        self::assertSame('release-txn-1', $releaseResult->payload['reversalTransactionId']);

        $refundResult = $provider->refund($invoice, $adjustment, 'refund');
        self::assertSame(PaymentDeduction::STATUS_REFUNDED, $refundResult->payload['status']);
        self::assertSame('refund-txn-1', $refundResult->payload['reversalTransactionId']);
    }

    public function testReleaseRejectsUnknownDeductionReference(): void
    {
        $deductionRepository = $this->createMock(PaymentDeductionRepository::class);
        $deductionRepository->method('findOneBy')->willReturn(null);
        $provider = new WalletBalanceAdjustmentProvider($this->createMock(PaymentDeductionServiceInterface::class), $deductionRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet balance deduction "missing-ref" not found.');

        $provider->release(new Invoice(), new PaymentAdjustmentResult(PaymentDeduction::TYPE_WALLET_BALANCE, 300, 'CNY', 'missing-ref'), 'cancel');
    }

    private static function deduction(Invoice $invoice): PaymentDeduction
    {
        return new PaymentDeduction($invoice, self::invoiceAndWallet()[1], 2, 300, 'CNY', 'deduction-ref-1');
    }

    /** @return array{Invoice, Wallet} */
    private static function invoiceAndWallet(): array
    {
        $user = new User();
        self::setId($user, 1);
        $invoice = (new Invoice())->setPayer($user)->setAmount(1000)->setCurrency('CNY');
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
