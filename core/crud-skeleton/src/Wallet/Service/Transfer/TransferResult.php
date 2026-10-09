<?php

declare(strict_types=1);

namespace App\Wallet\Service\Transfer;

use App\Wallet\Entity\Transaction;

final class TransferResult
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly int $fromWalletBalanceAfter,
        public readonly int $toWalletBalanceAfter,
    ) {}
}
