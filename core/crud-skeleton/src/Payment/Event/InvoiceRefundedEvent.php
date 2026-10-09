<?php

declare(strict_types=1);

namespace App\Payment\Event;

use App\Payment\DTO\PaymentRefundResult;
use App\Payment\Entity\Invoice;

final class InvoiceRefundedEvent
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?PaymentRefundResult $result = null,
    ) {}

    public function getInvoice(): Invoice { return $this->invoice; }
    public function getResult(): ?PaymentRefundResult { return $this->result; }
}
