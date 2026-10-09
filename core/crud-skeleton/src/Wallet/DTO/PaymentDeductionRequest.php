<?php

declare(strict_types=1);

namespace App\Wallet\DTO;

final readonly class PaymentDeductionRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $type,
        public int $amount,
        public string $currency,
        public array $options = [],
    ) {}
}
