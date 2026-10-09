<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\InventoryOutboxMessage;

interface InventoryOutboxServiceInterface
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
    ): InventoryOutboxMessage;
}
