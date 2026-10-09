<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Message\ReservationRequestedMessage;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use App\Inventory\Service\InventoryMessageIntegrityException;
use App\Inventory\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationRequestedHandler
{
    public function __construct(
        private InventoryConsumedEventRepository $consumedEventRepository,
        private InventoryService $inventoryService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ReservationRequestedMessage $message): void
    {
        [$eventId, $payload] = $this->validateEnvelope($message->envelope);
        $payloadHash = hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR));

        if ($this->isAlreadyConsumed($eventId, $payloadHash)) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $payload, $payloadHash): void {
            if ($this->isAlreadyConsumed($eventId, $payloadHash)) {
                return;
            }

            $this->entityManager->persist(new InventoryConsumedEvent(
                $eventId,
                'inventory.reservation.requested.v1',
                $payload['reservationId'],
                $payloadHash,
            ));

            // Rejections are persisted by the service as a normal reservation outcome.
            $this->inventoryService->reserve(
                $payload['reservationId'],
                $payload['storeUuid'],
                $payload['tradeOrderUuid'],
                $payload['storeOrderUuid'],
                $payload['items'],
                $payload['expiresAt'],
            );
        });
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{0: string, 1: array{reservationId: string, storeUuid: string, tradeOrderUuid: string, storeOrderUuid: string, items: list<array{lineId: string, catalogReference: string, quantity: string}>, expiresAt: \DateTimeImmutable}}
     */
    private function validateEnvelope(array $envelope): array
    {
        if (
            ($envelope['type'] ?? null) !== 'inventory.reservation.requested'
            || ($envelope['version'] ?? null) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.requested.v1 envelope type or version.');
        }
        $eventId = $envelope['eventId'] ?? null;
        $aggregateId = $envelope['aggregateId'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (!is_string($eventId) || !self::isUuid($eventId) || !is_string($aggregateId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.requested.v1 envelope.');
        }

        $reservationId = $payload['reservationId'] ?? null;
        $storeUuid = $payload['storeUuid'] ?? null;
        $tradeOrderUuid = $payload['tradeOrderUuid'] ?? null;
        $storeOrderUuid = $payload['storeOrderUuid'] ?? null;
        $items = $payload['items'] ?? null;
        $expiresAt = $payload['expiresAt'] ?? null;
        $requestedAt = $payload['requestedAt'] ?? null;
        if (
            !is_string($reservationId)
            || $aggregateId !== $reservationId
            || !self::isUuid($reservationId)
            || !is_string($storeUuid)
            || !self::isUuid($storeUuid)
            || !is_string($tradeOrderUuid)
            || !self::isUuid($tradeOrderUuid)
            || !is_string($storeOrderUuid)
            || !self::isUuid($storeOrderUuid)
            || !is_array($items)
            || $items === []
            || !is_string($expiresAt)
            || !is_string($requestedAt)
        ) {
            throw new \InvalidArgumentException('Invalid inventory reservation request payload.');
        }
        try {
            $expiration = self::parseDate($expiresAt);
            $requested = self::parseDate($requestedAt);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Reservation request timestamps must be ISO-8601 dates.', previous: $exception);
        }
        if ($expiration <= $requested) {
            throw new \InvalidArgumentException('Reservation expiry must be after its request time.');
        }

        $normalizedItems = [];
        $lineIds = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !is_string($item['lineId'] ?? null)
                || !self::isUuid($item['lineId'])
                || isset($lineIds[$item['lineId']])
                || !is_string($item['catalogReference'] ?? null)
                || !self::isUuid($item['catalogReference'])
                || !is_string($item['quantity'] ?? null)
                || !preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/', $item['quantity'])
                || !preg_match('/[1-9]/', $item['quantity'])
            ) {
                throw new \InvalidArgumentException('Invalid inventory reservation request item.');
            }
            $lineIds[$item['lineId']] = true;
            $normalizedItems[] = [
                'lineId' => $item['lineId'],
                'catalogReference' => $item['catalogReference'],
                'quantity' => $item['quantity'],
            ];
        }

        return [
            $eventId,
            [
                'reservationId' => $reservationId,
                'storeUuid' => $storeUuid,
                'tradeOrderUuid' => $tradeOrderUuid,
                'storeOrderUuid' => $storeOrderUuid,
                'items' => $normalizedItems,
                'expiresAt' => $expiration,
            ],
        ];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function parseDate(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new \InvalidArgumentException('Timestamp must be an ISO-8601 date.');
        }

        $normalized = str_replace('Z', '+00:00', $value);
        if (str_contains($normalized, '.')) {
            $parts = preg_split('/(?=[+-]\d{2}:\d{2}$)/', $normalized);
            if ($parts === false || count($parts) !== 2) {
                throw new \InvalidArgumentException('Timestamp must be an ISO-8601 date.');
            }
            [$seconds, $offset] = $parts;
            [$whole, $fraction] = explode('.', $seconds, 2);
            $normalized = $whole . '.' . str_pad($fraction, 6, '0') . $offset;
            $format = '!Y-m-d\TH:i:s.uP';
        } else {
            $format = '!Y-m-d\TH:i:sP';
        }
        $date = \DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Timestamp must be an ISO-8601 date.');
        }

        return $date;
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
