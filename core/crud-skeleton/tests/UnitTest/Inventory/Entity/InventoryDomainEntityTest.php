<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Entity;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Entity\LedgerEntry;
use App\Inventory\Entity\InventoryOutboxMessage;
use App\Inventory\Entity\Reservation;
use App\Inventory\Entity\Stock;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\ReservationLine;
use App\Inventory\Entity\SpecificationRecipe;
use PHPUnit\Framework\TestCase;

final class InventoryDomainEntityTest extends TestCase
{
    public function testStockLedgerAndOutboxLifecycle(): void
    {
        $material = new Material('domain-material', 'Domain Material', Material::KIND_RAW, 'kg');
        self::assertSame('domain-material', $material->getCode());
        self::assertSame('Domain Material', $material->getName());
        self::assertSame(Material::KIND_RAW, $material->getKind());
        self::assertSame('kg', $material->getUnit());
        $material->setMetadata(['origin' => 'test'])->setName('Renamed')->setUnit('gram');
        self::assertSame(['origin' => 'test'], $material->getMetadata());
        self::assertSame('Renamed', $material->getName());
        $material->setKind(Material::KIND_FINISHED)->setStatus(Material::STATUS_INACTIVE);
        self::assertSame(Material::KIND_FINISHED, $material->getKind());
        self::assertSame(Material::STATUS_INACTIVE, $material->getStatus());
        self::assertFalse($material->isActive());
        $stock = new Stock('store-uuid', $material);
        $stock->setAllowNegativeStock(true);
        $stock->adjustOnHand('5.000000');
        $stock->reserve('2.000000');
        self::assertSame('3.000000', $stock->getAvailableQuantity());
        $stock->release('2.000000');
        self::assertSame('0.000000', $stock->getReservedQuantity());

        $ledger = new LedgerEntry($stock, LedgerEntry::TYPE_ADJUSTMENT, '5.000000', '0.000000', 'test', 'reference');
        self::assertNull($ledger->getId());
        self::assertNotSame('', $ledger->getUuid());
        $outbox = new InventoryOutboxMessage('inventory.reservation.confirmed.v1', 'reservation', 'reservation-id', []);
        self::assertNull($outbox->getId());
        self::assertFalse($outbox->isPublished());
        $outbox->recordAttempt('temporary', new \DateTimeImmutable('+1 minute'));
        $outbox->markPublished();
        self::assertTrue($outbox->isPublished());
        self::assertNotSame('', $outbox->getEventId());
    }

    public function testReservationAndRecipeStateTransitions(): void
    {
        $material = new Material('recipe-material', 'Recipe Material', Material::KIND_RAW, 'piece');
        $recipe = new SpecificationRecipe('specification-uuid');
        self::assertSame('specification-uuid', $recipe->getSpecificationUuid());
        self::assertNotSame('', $recipe->getUuid());
        $line = new RecipeLine($material, '1.000000');
        $recipe->addLine($line);
        self::assertTrue($recipe->isActive());
        self::assertCount(1, $recipe->getLines());
        $recipe->removeLine($line)->setStatus(SpecificationRecipe::STATUS_INACTIVE);
        self::assertFalse($recipe->isActive());

        $reservation = new Reservation('reservation-id', 'store', 'trade-order', 'store-order', str_repeat('a', 64), null);
        self::assertNotSame('', $reservation->getUuid());
        self::assertSame('store', $reservation->getStoreUuid());
        self::assertSame('trade-order', $reservation->getTradeOrderUuid());
        self::assertSame('store-order', $reservation->getStoreOrderUuid());
        self::assertSame(str_repeat('a', 64), $reservation->getRequestHash());
        $reservation->addLine(new ReservationLine($material, '1.000000', ['specification-uuid']));
        $reservation->confirm();
        self::assertTrue($reservation->release());
        self::assertFalse($reservation->release());
        $reservation->reject('REJECTED', 'reason');
        self::assertSame('REJECTED', $reservation->getRejectionCode());
        self::assertSame('reason', $reservation->getRejectionReason());

        $event = new InventoryConsumedEvent('event', 'topic', 'aggregate', str_repeat('b', 64));
        self::assertSame('event', $event->getEventId());
    }

    public function testEntityValidationBranches(): void
    {
        $material = new Material('validation-material', 'Validation Material', Material::KIND_RAW, 'piece');
        $stock = new Stock('store', $material);
        $this->expectException(\LogicException::class);
        $stock->release('1.000000');
    }

    public function testMaterialAndRecipeRejectInvalidOrDuplicateChanges(): void
    {
        $material = new Material('duplicate-material', 'Duplicate Material', Material::KIND_RAW, 'piece');
        $recipe = new SpecificationRecipe('duplicate-specification');
        $recipe->addLine(new RecipeLine($material, '1.000000'));

        try {
            $recipe->addLine(new RecipeLine($material, '2.000000'));
            self::fail('Expected duplicate recipe material rejection.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        try {
            $recipe->setStatus('invalid');
            self::fail('Expected invalid recipe status rejection.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $material->setKind('invalid');
            self::fail('Expected invalid material kind rejection.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
