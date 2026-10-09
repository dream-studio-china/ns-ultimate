<?php

declare(strict_types=1);

namespace App\Payment\Event;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;

final class InvoiceFailedEvent
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?PaymentNotifyResult $result = null,
    ) {}

    public function getInvoice(): Invoice { return $this->invoice; }
    public function getResult(): ?PaymentNotifyResult { return $this->result; }
}
