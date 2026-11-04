<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

/** Server-only preparation capability; never populated from request JSON. */
final readonly class CustomerPaymentContext
{
    public function __construct(
        public string $orderUuid,
        public string $payerUuid,
        public string $storeUuid,
        public int $amount,
        public string $currency,
    ) {}
}
