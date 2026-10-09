<?php

declare(strict_types=1);

namespace App\Payment\Service\Adjustment;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;

interface PaymentAdjustmentProviderInterface
{
    public static function getName(): string;

    /** @param array<string, mixed> $options */
    public function supports(Invoice $invoice, string $payment, array $options): bool;

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult;

    /** @return PaymentAdjustmentResult[] */
    public function applied(Invoice $invoice): array;

    public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult;

    public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult;
}
