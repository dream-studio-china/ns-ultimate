<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\Entity\TradeOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TradeOutboxService implements TradeOutboxServiceInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(string $topic, string $aggregateType, string $aggregateId, array $payload): TradeOutboxMessage
    {
        $message = new TradeOutboxMessage($topic, $aggregateType, $aggregateId, $payload);
        $this->entityManager->persist($message);

        return $message;
    }
}
