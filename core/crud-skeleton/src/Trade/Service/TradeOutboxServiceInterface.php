<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\Entity\TradeOutboxMessage;

interface TradeOutboxServiceInterface
{
    /** @param array<string, mixed> $payload */
    public function record(string $topic, string $aggregateType, string $aggregateId, array $payload): TradeOutboxMessage;
}
