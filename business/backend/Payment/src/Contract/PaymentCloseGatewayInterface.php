<?php

declare(strict_types=1);

namespace NsUltimate\Business\Payment\Contract;

use App\Payment\Entity\Invoice;
use NsUltimate\Business\Payment\DTO\PaymentCloseResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Optional channel capability; it never changes the Invoice or Trade order. */
#[AutoconfigureTag('ns.payment.close_gateway')]
interface PaymentCloseGatewayInterface
{
    public static function payment(): string;

    /** Confirm all channel attempts are terminal and cannot collect, or fail closed.
     * A verified collection discovered during closure must be returned as payment.
     * Implementations must support retry after a timeout or local rollback. */
    public function close(Invoice $invoice): PaymentCloseResult;
}
