<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * A fully evaluated allocation ready for persistence: recipient resolved, exact
 * amount computed, and rounded posting unit assigned.
 */
final readonly class ComputedAllocation
{
    /**
     * @param array<string, mixed> $recipientSnapshot
     * @param array<string, mixed> $evidence
     * @param array<string, mixed>|null $sourceItemSnapshot
     */
    public function __construct(
        public string $allocationKey,
        public RecipientReference $recipient,
        public string $exactAmountQuantum,
        public string $postingAmount,
        public int $postingScale,
        public string $roundingDeltaQuantum,
        public ?int $roundingRank,
        public string $ruleCode,
        public ?string $ruleVersionUuid,
        public string $reasonCode,
        public array $recipientSnapshot,
        public array $evidence,
        public ?string $sourceItemId,
        public ?array $sourceItemSnapshot,
    ) {
    }
}
