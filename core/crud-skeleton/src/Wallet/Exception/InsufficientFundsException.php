<?php

declare(strict_types=1);

namespace App\Wallet\Exception;

class InsufficientFundsException extends \RuntimeException
{
    public function __construct(int $walletId, int $available, int $requested)
    {
        parent::__construct(sprintf(
            'Insufficient funds: wallet #%d has %d but %d requested',
            $walletId, $available, $requested
        ));
    }
}
