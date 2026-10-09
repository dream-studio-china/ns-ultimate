<?php

declare(strict_types=1);

namespace App\Settlement\Context;

use App\Settlement\Contract\SettlementContext;
use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Contract\SettlementSubject;

interface SettlementContextResolverInterface
{
    public static function getName(): string;

    public function supports(SettlementSubject $subject): bool;

    public function resolve(
        SettlementFunding $funding,
        SettlementSubject $subject,
        \DateTimeImmutable $asOf,
    ): SettlementContext;
}
