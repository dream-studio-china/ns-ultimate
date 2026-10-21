<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

class PrinterProviderException extends \RuntimeException
{
    public function __construct(public readonly string $providerCode, string $message = 'Printer provider request failed.')
    {
        parent::__construct($message);
    }
}
