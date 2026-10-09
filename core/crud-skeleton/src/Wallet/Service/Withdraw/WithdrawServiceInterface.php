<?php

declare(strict_types=1);

namespace App\Wallet\Service\Withdraw;

use App\Wallet\Entity\Voucher;

/**
 * The single gate for funds leaving the wallet system. Every withdrawal must be
 * backed by a registered voucher type; a debit is performed atomically with the
 * voucher record (single-sided: fromWallet only, no toWallet).
 */
interface WithdrawServiceInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function withdraw(
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
     * Reverse an applied debit (withdrawal) voucher with a single-sided credit:
     * the withdrawn funds return to the source wallet.
     *
     * @param array<string, mixed> $options
     */
    public function reverse(string $voucherUuid, string $reason, array $options = []): Voucher;
}
