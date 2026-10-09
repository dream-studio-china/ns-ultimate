<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\Entity\StoreOutboxMessage;

interface StoreOutboxServiceInterface
{
    /** @param array<string, mixed> $payload */
    public function record(string $topic, string $aggregateType, string $aggregateId, array $payload): StoreOutboxMessage;
}
