<?php

declare(strict_types=1);

namespace App\Payment\Event;

use App\Payment\Entity\Invoice;

final class InvoiceCancelledEvent
{
    public function __construct(private readonly Invoice $invoice) {}
    public function getInvoice(): Invoice { return $this->invoice; }
}
