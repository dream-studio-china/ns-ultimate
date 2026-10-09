<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Core\Service\BaseServiceInterface;
use App\Payment\Entity\Invoice;
use App\Wallet\DTO\PaymentDeductionRequest;
use App\Wallet\Entity\PaymentDeduction;

/** @extends BaseServiceInterface<PaymentDeduction> */
interface PaymentDeductionServiceInterface extends BaseServiceInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function createRequestFromOptions(Invoice $invoice, array $options): ?PaymentDeductionRequest;

    /**
     * @param array<string, mixed> $options
     */
    public function applyFromOptions(Invoice $invoice, array $options): ?PaymentDeduction;

    /**
     * @param array<string, mixed> $options
     */
    public function apply(Invoice $invoice, int $amount, string $currency, array $options = [], string $type = PaymentDeduction::TYPE_WALLET_BALANCE): PaymentDeduction;

    public function release(Invoice $invoice, string $reason): ?PaymentDeduction;

    public function refund(Invoice $invoice, string $reason): ?PaymentDeduction;

    public function sumAppliedAmount(Invoice $invoice): int;

    public function findApplied(Invoice $invoice): ?PaymentDeduction;

    public function hasApplied(Invoice $invoice): bool;
}
