<?php

declare(strict_types=1);

namespace App\Wallet\Service;

/**
 * Reconciliation surface for the boundary ledger.
 */
interface ReconciliationServiceInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listBoundaryVouchers(
        string $currency,
        ?string $fundSource = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array;

    /**
     * @param list<array<string, mixed>> $externalLines
     * @return array<string, mixed>
     */
    public function reconcileAgainstExternal(string $currency, array $externalLines): array;
}
