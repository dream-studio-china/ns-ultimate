<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * Settlement-safe receipt returned by the voucher port. `externalReference` is
 * opaque to Settlement and stored verbatim.
 */
final readonly class VoucherPostingReceipt
{
    public function __construct(
        public string $externalReference,
        public string $idempotencyKey,
        public \DateTimeImmutable $processedAt,
        public string $status,
    ) {
    }
}
