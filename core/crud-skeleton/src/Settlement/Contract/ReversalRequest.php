<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

final readonly class ReversalRequest
{
    public function __construct(
        public string $reversalUuid,
        public string $reasonCode,
        public string $reasonDetail,
        public string $requestedBy,
    ) {
    }
}
