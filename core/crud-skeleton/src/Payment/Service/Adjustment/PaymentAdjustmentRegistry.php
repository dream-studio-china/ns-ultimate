<?php

declare(strict_types=1);

namespace App\Payment\Service\Adjustment;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PaymentAdjustmentRegistry
{
    /** @var array<string, PaymentAdjustmentProviderInterface> */
    private array $providers = [];

    /** @param iterable<PaymentAdjustmentProviderInterface> $providers */
    public function __construct(#[AutowireIterator('payment.adjustment_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider::getName()] = $provider;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return PaymentAdjustmentProviderInterface[]
     */
    public function applicable(Invoice $invoice, string $payment, array $options): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (PaymentAdjustmentProviderInterface $provider): bool => $provider->supports($invoice, $payment, $options),
        ));
    }

    /**
     * @param array<string, mixed> $options
     * @return PaymentAdjustmentResult[]
     */
    public function apply(Invoice $invoice, string $payment, array $options): array
    {
        $context = new PaymentAdjustmentContext($invoice, $payment, $invoice->getAmount(), $invoice->getCurrency(), $options);
        $results = [];
        foreach ($this->applicable($invoice, $payment, $options) as $provider) {
            $results[] = $provider->apply($context);
        }

        return $results;
    }

    /** @return PaymentAdjustmentResult[] */
    public function applied(Invoice $invoice): array
    {
        $results = [];
        foreach ($this->providers as $provider) {
            array_push($results, ...$provider->applied($invoice));
        }

        return $results;
    }

    public function sumAppliedAmount(Invoice $invoice): int
    {
        $sum = 0;
        foreach ($this->applied($invoice) as $adjustment) {
            $sum += $adjustment->amount;
        }

        return $sum;
    }

    public function hasApplied(Invoice $invoice): bool
    {
        return $this->applied($invoice) !== [];
    }

    /** @return PaymentAdjustmentResult[] */
    public function releaseApplied(Invoice $invoice, string $reason): array
    {
        $results = [];
        foreach ($this->applied($invoice) as $adjustment) {
            $results[] = $this->provider($adjustment)->release($invoice, $adjustment, $reason);
        }

        return $results;
    }

    /** @return PaymentAdjustmentResult[] */
    public function refundApplied(Invoice $invoice, string $reason): array
    {
        $results = [];
        foreach ($this->applied($invoice) as $adjustment) {
            $results[] = $this->provider($adjustment)->refund($invoice, $adjustment, $reason);
        }

        return $results;
    }

    private function provider(PaymentAdjustmentResult $adjustment): PaymentAdjustmentProviderInterface
    {
        return $this->providers[$adjustment->provider]
            ?? throw new \RuntimeException(sprintf('Payment adjustment provider "%s" not found.', $adjustment->provider));
    }
}
