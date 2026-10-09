<?php

declare(strict_types=1);

namespace App\Settlement\Integration\Fake;

use App\Settlement\Contract\ConfirmedAllocation;
use App\Settlement\Contract\PostedAllocation;
use App\Settlement\Contract\ReversalRequest;
use App\Settlement\Contract\VoucherPostingReceipt;
use App\Settlement\Exception\SettlementVoucherException;
use App\Settlement\Port\SettlementVoucherPort;

/**
 * In-memory, deterministic voucher adapter used by tests and as the default wiring
 * until a host Wallet adapter is provided. Posting always succeeds; a configured
 * `failReversalWithInsufficientFunds` flag simulates the recovery path.
 */
final class InMemorySettlementVoucherPort implements SettlementVoucherPort
{
    /** @var array<string, VoucherPostingReceipt> */
    public array $posts = [];

    /** @var array<string, VoucherPostingReceipt> */
    public array $reversals = [];

    public bool $failAllPosts = false;

    public bool $failReversalWithInsufficientFunds = false;

    public function post(ConfirmedAllocation $allocation): VoucherPostingReceipt
    {
        if ($this->failAllPosts) {
            throw new SettlementVoucherException('fake posting rejection', false, 'rejected');
        }
        $receipt = new VoucherPostingReceipt(
            externalReference: 'fake-voucher:' . $allocation->allocationUuid,
            idempotencyKey: $allocation->postingIdempotencyKey,
            processedAt: new \DateTimeImmutable(),
            status: 'applied',
        );
        $this->posts[$allocation->allocationUuid] = $receipt;
        return $receipt;
    }

    public function reverse(PostedAllocation $allocation, ReversalRequest $request): VoucherPostingReceipt
    {
        if ($this->failReversalWithInsufficientFunds) {
            throw new SettlementVoucherException('insufficient funds', false, 'insufficient_funds');
        }
        $receipt = new VoucherPostingReceipt(
            externalReference: 'fake-reversal:' . $allocation->allocationUuid,
            idempotencyKey: $allocation->reversalIdempotencyKey,
            processedAt: new \DateTimeImmutable(),
            status: 'reversed',
        );
        $this->reversals[$allocation->allocationUuid] = $receipt;
        return $receipt;
    }
}
