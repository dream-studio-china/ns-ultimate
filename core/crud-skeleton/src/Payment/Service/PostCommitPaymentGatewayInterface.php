<?php

declare(strict_types=1);

namespace App\Payment\Service;

/**
 * Marks gateways whose provider initiation must run after the Invoice
 * transaction commits, so their external attempt can be durably recorded first.
 */
interface PostCommitPaymentGatewayInterface extends PaymentGatewayInterface
{
}
