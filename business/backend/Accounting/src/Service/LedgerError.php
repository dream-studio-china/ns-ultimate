<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class LedgerError extends \RuntimeException
{
    public function __construct(string $code, public readonly int $status = 400)
    {
        parent::__construct($code);
    }
}
