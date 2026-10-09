<?php

declare(strict_types=1);

namespace App\Settlement\Message;

/**
 * Durable envelope carrying a confirmed funding fact into the Settlement boundary.
 * Mirrors `App\Settlement\Contract\SettlementFunding` for Messenger transport.
 */
final readonly class SettlementFundingConfirmedMessage
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $fundingId,
        public string $eventId,
        public string $sourceType,
        public string $sourceId,
        public string $confirmationReference,
        public string $currency,
        public string $amountQuantum,
        public int $calculationScale,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public string $fundingKind = 'confirmed',
        public array $snapshot = [],
    ) {
    }
}
