<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

use App\Settlement\Service\Money\QuantumAmount;

/**
 * The frozen source context available to rule evaluation. Facts are flat,
 * explicitly named scalars. Recipient candidates are named snapshot references.
 */
final readonly class SettlementContext
{
    /**
     * @param array<string, mixed> $facts
     * @param array<string, RecipientReference> $recipientCandidates
     * @param list<SettlementItemContext> $items
     */
    public function __construct(
        public SettlementSubject $subject,
        public string $currency,
        public string $distributableAmountQuantum,
        public int $calculationScale,
        public array $facts,
        public array $recipientCandidates,
        public string $sourceSnapshotVersion,
        public \DateTimeImmutable $resolvedAt,
        public array $items = [],
    ) {
    }

    public function distributableAmount(): QuantumAmount
    {
        return QuantumAmount::of($this->distributableAmountQuantum, $this->currency, $this->calculationScale);
    }

    public function fact(string $name): mixed
    {
        return $this->facts[$name] ?? null;
    }

    public function hasFact(string $name): bool
    {
        return array_key_exists($name, $this->facts);
    }

    public function forItem(SettlementItemContext $item): self
    {
        return new self(
            subject: $this->subject,
            currency: $this->currency,
            distributableAmountQuantum: $this->distributableAmountQuantum,
            calculationScale: $this->calculationScale,
            facts: array_replace($this->facts, $item->facts),
            recipientCandidates: array_replace($this->recipientCandidates, $item->recipientCandidates),
            sourceSnapshotVersion: $this->sourceSnapshotVersion,
            resolvedAt: $this->resolvedAt,
        );
    }
}
