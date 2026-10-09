<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\Reservation;
use App\Inventory\Entity\Stock;

interface InventoryServiceInterface
{
    /** @return array{storeUuid: string, materialUuid: string, exists: bool, onHandQuantity: string, reservedQuantity: string, availableQuantity: string, allowNegativeStock: bool} */
    public function getStockView(string $storeUuid, string $materialUuid): array;

    public function setStockAllowNegative(
        string $storeUuid,
        string $materialUuid,
        bool $allowNegativeStock,
    ): Stock;

    public function adjustStock(
        string $storeUuid,
        string $materialUuid,
        string $quantityDelta,
        string $reason,
        ?string $referenceId = null,
        ?string $actorReference = null,
        ?bool $allowNegativeStock = null,
    ): Stock;

    /** @param list<array{lineId: string, catalogReference: string, quantity: string}> $items */
    public function reserve(
        string $reservationId,
        string $storeUuid,
        string $tradeOrderUuid,
        string $storeOrderUuid,
        array $items,
        ?\DateTimeImmutable $expiresAt = null,
    ): Reservation;

    public function release(string $reservationId, ?string $reason = null): Reservation;

    public function releaseExpiredReservations(): int;
}
