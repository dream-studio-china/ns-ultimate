<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Inventory\Message\ReservationRejectedMessage;
use App\Store\Entity\StoreConsumedEvent;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Service\StoreOrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationRejectedHandler
{
    public function __construct(
        private StoreConsumedEventRepository $consumedEventRepository,
        private StoreOrderRepository $storeOrderRepository,
        private StoreOrderServiceInterface $storeOrderService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ReservationRejectedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (($message->envelope['type'] ?? null) !== 'inventory.reservation.rejected'
            || ($message->envelope['version'] ?? null) !== 1
            || !is_string($eventId)
            || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.rejected.v1 envelope.');
        }
        foreach (['reservationId', 'storeUuid', 'tradeOrderUuid', 'storeOrderUuid', 'reasonCode', 'reason', 'rejectedAt'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new \InvalidArgumentException('Invalid inventory reservation rejection payload.');
            }
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
                'inventory.reservation.rejected.v1',
                $payload['reservationId'],
                hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
            ));

            $storeOrder = $this->storeOrderRepository->findOneByUuid($payload['storeOrderUuid']);
            if ($storeOrder === null
                || $storeOrder->getStore()->getUuid() !== $payload['storeUuid']
                || $storeOrder->getTradeOrderUuid() !== $payload['tradeOrderUuid']
                || $storeOrder->getReservationId() !== $payload['reservationId']
                || $storeOrder->getOperationalStatus() !== StoreOrder::STATUS_AWAITING_INVENTORY) {
                return;
            }

            $this->storeOrderService->reject($storeOrder, $payload['reasonCode'], $payload['reason']);
        });
    }
}
