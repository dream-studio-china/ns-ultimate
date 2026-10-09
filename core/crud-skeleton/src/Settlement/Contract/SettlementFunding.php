<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * A confirmed, trusted funding fact. Purely scalar; never carries a foreign entity.
 */
final readonly class SettlementFunding
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $fundingId,
        public string $sourceType,
        public string $sourceId,
        public string $confirmationReference,
        public string $currency,
        public string $amountQuantum,
        public int $calculationScale,
        public \DateTimeImmutable $confirmedAt,
        public string $idempotencyKey,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public string $fundingKind = 'confirmed',
        public array $snapshot = [],
    ) {
    }
}
