<?php

declare(strict_types=1);

namespace App\Wallet\Exception;

class WalletFrozenException extends \RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet #%d is frozen and cannot be used for transactions', $walletId));
    }
}
