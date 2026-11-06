<?php

declare(strict_types=1);

namespace NsUltimate\Business\Payment\DTO;

use App\Payment\DTO\PaymentNotifyResult;

final readonly class PaymentCloseResult
{
    public function __construct(public bool $closed = false, public ?PaymentNotifyResult $payment = null) {}
}
