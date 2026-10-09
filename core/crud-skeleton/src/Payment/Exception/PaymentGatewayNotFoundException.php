<?php

declare(strict_types=1);

namespace App\Payment\Exception;

final class PaymentGatewayNotFoundException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Payment gateway "%s" is not registered.', $name));
    }
}
