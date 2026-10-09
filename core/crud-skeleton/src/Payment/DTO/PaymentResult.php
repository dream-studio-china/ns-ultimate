<?php

declare(strict_types=1);

namespace App\Payment\DTO;

use App\Payment\Entity\Invoice;

final readonly class PaymentResult
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        public Invoice $invoice,
        public string $status,
        public ?string $payUrl = null,
        public ?string $qrCode = null,
        public ?array $payload = null,
        public ?string $message = null,
    ) {}
}
