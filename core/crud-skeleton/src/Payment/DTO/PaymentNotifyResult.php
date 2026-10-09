<?php

declare(strict_types=1);

namespace App\Payment\DTO;

final readonly class PaymentNotifyResult
{
    /** @param array<string, mixed> $rawData */
    public function __construct(
        public string $payment,
        public string $outTradeNo,
        public string $status,
        public int $amount,
        public string $currency = 'CNY',
        public ?string $transactionId = null,
        public ?\DateTimeImmutable $paidAt = null,
        public array $rawData = [],
        public string $responseBody = 'SUCCESS',
    ) {}
}
