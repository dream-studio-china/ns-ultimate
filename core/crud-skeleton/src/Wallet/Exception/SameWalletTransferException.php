<?php

declare(strict_types=1);

namespace App\Wallet\Exception;

class SameWalletTransferException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Cannot transfer to the same wallet');
    }
}
