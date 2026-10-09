<?php

declare(strict_types=1);

namespace App\Settlement\Message;

final readonly class SettlementAllocationPostingMessage
{
    public function __construct(
        public string $allocationUuid,
        public string $planUuid,
    ) {
    }
}
