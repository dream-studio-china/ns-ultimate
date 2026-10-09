<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Settlement\Entity\SettlementOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

class SettlementOutboxService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
    ): SettlementOutboxMessage {
        $message = new SettlementOutboxMessage($topic, $aggregateType, $aggregateId, $payload);
        $this->em->persist($message);
        return $message;
    }
}
