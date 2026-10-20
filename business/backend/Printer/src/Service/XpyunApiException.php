<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

final class XpyunApiException extends \RuntimeException
{
    public function __construct(public readonly string $providerCode)
    {
        parent::__construct(sprintf('Xpyun API returned code %s.', $providerCode));
    }
}
