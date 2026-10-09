<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

use App\Settlement\Service\Money\QuantumAmount;

/**
 * An allocation that is eligible to be posted: the recipient is resolved and its
 * final posting amount/scale/currency are fixed.
 */
final readonly class ConfirmedAllocation
{
    public function __construct(
        public string $allocationUuid,
        public string $planUuid,
        public RecipientReference $recipient,
        public string $currency,
        public int $postingScale,
        public QuantumAmount $postingAmount,
        public string $postingIdempotencyKey,
        public string $reasonCode,
    ) {
    }

    public function postingMinor(): string
    {
        return $this->postingAmount->toPostingMinor($this->postingScale);
    }
}
