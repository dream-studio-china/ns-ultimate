<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * An opaque, extensible recipient reference. Settlement stores these as scalar
 * type/id pairs; the account host resolves them to Wallet accounts.
 */
final readonly class RecipientReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
    }

    public function asString(): string
    {
        return sprintf('%s:%s', $this->type, $this->id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
