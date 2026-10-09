<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\InventoryOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final class InventoryOutboxService implements InventoryOutboxServiceInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
    ): InventoryOutboxMessage
    {
        $message = new InventoryOutboxMessage($topic, $aggregateType, $aggregateId, $payload);
        $this->entityManager->persist($message);

        return $message;
    }
}
