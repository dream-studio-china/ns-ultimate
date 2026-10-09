<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use App\Inventory\Repository\ReservationRepository;
use App\Inventory\Service\InventoryMessageIntegrityException;
use App\Inventory\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationReleaseRequestedHandler
{
    public function __construct(
        private InventoryConsumedEventRepository $consumedEventRepository,
        private ReservationRepository $reservationRepository,
        private InventoryService $inventoryService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ReservationReleaseRequestedMessage $message): void
    {
        [$eventId, $reservationId, $reason, $correlations] = $this->validateEnvelope($message->envelope);
        $payloadHash = hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR));
        if ($this->isAlreadyConsumed($eventId, $payloadHash)) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $reservationId, $reason, $correlations, $payloadHash): void {
            if ($this->isAlreadyConsumed($eventId, $payloadHash)) {
                return;
            }
            $reservation = $this->reservationRepository->findOneByReservationId($reservationId);
            if ($reservation === null) {
                throw new \InvalidArgumentException('Reservation was not found.');
            }
            if (
                $reservation->getStoreUuid() !== $correlations['storeUuid']
                || $reservation->getTradeOrderUuid() !== $correlations['tradeOrderUuid']
                || $reservation->getStoreOrderUuid() !== $correlations['storeOrderUuid']
            ) {
                throw new InventoryMessageIntegrityException('Reservation release correlations do not match the reservation.');
            }
            $this->entityManager->persist(new InventoryConsumedEvent(
                $eventId,
                'inventory.reservation.release.requested.v1',
                $reservationId,
                $payloadHash,
            ));
            $this->inventoryService->release($reservationId, $reason);
        });
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{0: string, 1: string, 2: string, 3: array{storeUuid: string, tradeOrderUuid: string, storeOrderUuid: string}}
     */
    private function validateEnvelope(array $envelope): array
    {
        if (
            ($envelope['type'] ?? null) !== 'inventory.reservation.release.requested'
            || ($envelope['version'] ?? null) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.release.requested.v1 envelope type or version.');
        }
        $eventId = $envelope['eventId'] ?? null;
        $aggregateId = $envelope['aggregateId'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (!is_string($eventId) || !self::isUuid($eventId) || !is_string($aggregateId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.release.requested.v1 envelope.');
        }
        $reservationId = $payload['reservationId'] ?? null;
        $reason = $payload['reason'] ?? null;
        $requestedAt = $payload['requestedAt'] ?? null;
        foreach (['storeUuid', 'tradeOrderUuid', 'storeOrderUuid'] as $field) {
            if (!is_string($payload[$field] ?? null) || !self::isUuid($payload[$field])) {
                throw new \InvalidArgumentException('Invalid inventory reservation release correlation.');
            }
        }
        if (
            !is_string($reservationId)
            || $aggregateId !== $reservationId
            || !self::isUuid($reservationId)
            || !is_string($reason)
            || trim($reason) === ''
            || !is_string($requestedAt)
        ) {
            throw new \InvalidArgumentException('Invalid inventory reservation release payload.');
        }
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $requestedAt)) {
                throw new \InvalidArgumentException('Timestamp must be an ISO-8601 date.');
            }
            new \DateTimeImmutable($requestedAt);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Reservation release request time must be an ISO-8601 date.', previous: $exception);
        }

        return [
            $eventId,
            $reservationId,
            $reason,
            [
                'storeUuid' => $payload['storeUuid'],
                'tradeOrderUuid' => $payload['tradeOrderUuid'],
                'storeOrderUuid' => $payload['storeOrderUuid'],
            ],
        ];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function isAlreadyConsumed(string $eventId, string $payloadHash): bool
    {
        $consumed = $this->consumedEventRepository->findOneByEventId($eventId);
        if ($consumed === null) {
            return false;
        }
        if (!hash_equals($consumed->getPayloadHash(), $payloadHash)) {
            throw new InventoryMessageIntegrityException('Event ID was reused with a different payload.');
        }

        return true;
    }
}
