<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseServiceInterface;
use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;

/** @extends BaseServiceInterface<Wallet> */
interface WalletServiceInterface extends BaseServiceInterface
{
    /**
     * Verify the boundary invariant per unit of account:
     * SUM(balance) == SUM(applied credit vouchers) - SUM(applied debit vouchers).
     *
     * @return array<string, mixed>
     */
    public function verifyBalance(): array;

    /**
     * @return array<string, mixed>
     */
    public function verifyBalanceForUser(User $user): array;

    /**
     * Reconcile every wallet against its transaction-derived expected balance.
     *
     * @return array{reconciled: int, adjustments: list<array<string,mixed>>}
     */
    public function reconcile(): array;
}
