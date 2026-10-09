<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * An already-posted allocation, used only to reverse the exact original voucher.
 */
final readonly class PostedAllocation
{
    public function __construct(
        public string $allocationUuid,
        public string $planUuid,
        public RecipientReference $recipient,
        public string $currency,
        public int $postingScale,
        public string $postingAmount,
        public string $postingIdempotencyKey,
        public string $externalReference,
        public string $reversalIdempotencyKey,
    ) {
    }
}
