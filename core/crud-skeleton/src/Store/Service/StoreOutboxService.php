<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\Entity\StoreOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StoreOutboxService implements StoreOutboxServiceInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(string $topic, string $aggregateType, string $aggregateId, array $payload): StoreOutboxMessage
    {
        $message = new StoreOutboxMessage($topic, $aggregateType, $aggregateId, $payload);
        $this->entityManager->persist($message);

        return $message;
    }
}
