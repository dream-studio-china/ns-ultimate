<?php

declare(strict_types=1);

namespace App\Payment\DTO;

final readonly class PaymentAdjustmentResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $provider,
        public int $amount,
        public string $currency,
        public string $referenceId,
        public array $payload = [],
    ) {}
}
