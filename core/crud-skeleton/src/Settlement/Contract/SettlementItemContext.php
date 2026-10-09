<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/** A frozen order-item projection evaluated inside one order-level settlement plan. */
final readonly class SettlementItemContext
{
    /**
     * @param array<string, mixed> $facts
     * @param array<string, RecipientReference> $recipientCandidates
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $id,
        public array $facts,
        public array $recipientCandidates = [],
        public array $snapshot = [],
    ) {
    }
}
