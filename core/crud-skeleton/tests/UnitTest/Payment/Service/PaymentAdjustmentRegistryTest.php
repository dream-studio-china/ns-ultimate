<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Payment\Service;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentAdjustmentRegistryTest extends TestCase
{
    public function testAppliesAndReversesMatchingProviders(): void
    {
        $invoice = (new Invoice())->setAmount(1000)->setCurrency('CNY');
        $provider = new RegistryTestAdjustmentProvider();
        $registry = new PaymentAdjustmentRegistry([$provider]);

        self::assertCount(1, $registry->applicable($invoice, 'mock', ['walletAmount' => 300]));
        self::assertSame([], $registry->applicable($invoice, 'mock', []));

        $applied = $registry->apply($invoice, 'mock', ['walletAmount' => 300]);
        self::assertSame(300, $applied[0]->amount);
        self::assertSame(300, $registry->sumAppliedAmount($invoice));
        self::assertTrue($registry->hasApplied($invoice));

        self::assertSame('released', $registry->releaseApplied($invoice, 'cancel')[0]->payload['status']);
        self::assertSame('refunded', $registry->refundApplied($invoice, 'refund')[0]->payload['status']);
    }
}

final class RegistryTestAdjustmentProvider implements PaymentAdjustmentProviderInterface
{
    private ?PaymentAdjustmentResult $applied = null;

    public static function getName(): string { return 'wallet_balance'; }

    public function supports(Invoice $invoice, string $payment, array $options): bool
    {
        return isset($options['walletAmount']);
    }

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult
    {
        return $this->applied = new PaymentAdjustmentResult(self::getName(), (int) $context->options['walletAmount'], $context->currency, 'adj-ref');
    }

    public function applied(Invoice $invoice): array
    {
        return $this->applied ? [$this->applied] : [];
    }

    public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return new PaymentAdjustmentResult($adjustment->provider, $adjustment->amount, $adjustment->currency, $adjustment->referenceId, ['status' => 'released']);
    }

    public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return new PaymentAdjustmentResult($adjustment->provider, $adjustment->amount, $adjustment->currency, $adjustment->referenceId, ['status' => 'refunded']);
    }
}
