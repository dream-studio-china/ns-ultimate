<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Inventory\Message\ReservationReleasedMessage;
use App\Store\Entity\StoreConsumedEvent;
use App\Store\Repository\StoreConsumedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationReleasedHandler
{
    public function __construct(
        private StoreConsumedEventRepository $consumedEventRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ReservationReleasedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (($message->envelope['type'] ?? null) !== 'inventory.reservation.released'
            || ($message->envelope['version'] ?? null) !== 1
            || !is_string($eventId)
            || !is_array($payload)
            || !is_string($payload['reservationId'] ?? null)
            || !is_string($payload['releasedAt'] ?? null)) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.released.v1 envelope.');
        }
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $payload, $message): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }
            $this->entityManager->persist(new StoreConsumedEvent(
                $eventId,
                'inventory.reservation.released.v1',
                $payload['reservationId'],
                hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
            ));
        });
    }
}
