<?php

declare(strict_types=1);

namespace App\Payment\DTO;

use App\Payment\Entity\Invoice;

final readonly class PaymentRefundResult
{
    /** @param array<string, mixed> $rawData */
    public function __construct(
        public Invoice $invoice,
        public int $amount,
        public string $status,
        public ?string $refundId = null,
        public array $rawData = [],
    ) {}
}
