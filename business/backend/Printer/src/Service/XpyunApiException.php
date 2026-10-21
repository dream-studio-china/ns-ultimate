<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

final class XpyunApiException extends PrinterProviderException
{
    public function __construct(string $providerCode)
    {
        parent::__construct($providerCode, sprintf('Xpyun API returned code %s.', $providerCode));
    }
}
