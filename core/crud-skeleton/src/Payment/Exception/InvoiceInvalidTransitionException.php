<?php

declare(strict_types=1);

namespace App\Payment\Exception;

use App\Payment\Entity\Invoice;

final class InvoiceInvalidTransitionException extends \RuntimeException
{
    public function __construct(Invoice $invoice, string $transition)
    {
        parent::__construct(sprintf(
            'Invoice %s cannot apply transition "%s" from status "%s".',
            $invoice->getOutTradeNo(),
            $transition,
            $invoice->getStatus(),
        ));
    }
}
