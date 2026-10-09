<?php

declare(strict_types=1);

namespace App\Wallet\Service\Transfer;

use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Entity\Wallet;

interface TransferServiceInterface
{
    /**
     * Transfer amount from source wallet to target wallet.
     * Transactions are atomic: either both debit and credit happen, or neither.
     * Uses pessimistic locking to prevent race conditions.
     *
     * @return TransferResult containing the transaction record
     * @throws InsufficientFundsException
     * @throws WalletFrozenException
     * @throws SameWalletTransferException
     */
    public function transfer(int $fromWalletId, int $toWalletId, int $amount, ?string $referenceId = null, ?string $description = null): TransferResult;

    /**
     * Freeze `$amount` of the wallet's available balance into the held bucket.
     * Does not change the total balance and does not write a ledger row;
     * the WalletWithdrawal/hold entity owns the audit record.
     *
     * @return Wallet the updated wallet
     * @throws InsufficientFundsException
     * @throws WalletFrozenException
     */
    public function hold(int $walletId, int $amount, ?string $description = null): Wallet;

    /**
     * Move `$amount` from the held bucket back to available balance.
     *
     * @return Wallet the updated wallet
     * @throws InsufficientFundsException
     * @throws WalletFrozenException
     */
    public function release(int $walletId, int $amount, ?string $description = null): Wallet;
}
