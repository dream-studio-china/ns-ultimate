<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Wallet\Entity\Voucher;

/**
 * The single gate for funds entering the wallet system. Every deposit must be
 * backed by a registered voucher type.
 */
interface DepositServiceInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function deposit(
        string $voucherType,
        string $voucherId,
        int $walletId,
        int $amount,
        string $currency,
        string $referenceId,
        string $createdBy,
        ?string $reason = null,
        string $fundSource = Voucher::FUND_SOURCE_EXTERNAL,
        array $options = []
    ): Voucher;

    /**
     * Reverse an applied credit (deposit) voucher with a single-sided debit.
     *
     * @param array<string, mixed> $options
     */
    public function reverse(string $voucherUuid, string $reason, array $options = []): Voucher;
}
