<?php

declare(strict_types=1);

namespace App\Settlement\Exception;

/**
 * A classified voucher-boundary failure. `retryable` distinguishes temporary
 * failures (retry) from mapping/integrity failures (manual review).
 */
class SettlementVoucherException extends SettlementException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?string $classification = null,
    ) {
        parent::__construct($message);
    }
}
