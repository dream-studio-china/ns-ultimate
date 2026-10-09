<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Inventory\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Inventory\Entity\Reservation;
use App\Inventory\Entity\Stock;
use App\Inventory\Entity\Material;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class InventoryEntityCoverageTest extends TestCase
{
    public function testReservationExposesNullDatabaseIdBeforePersist(): void
    {
        $reservation = new Reservation(
            '00000000-0000-4000-8000-000000000401',
            '00000000-0000-4000-8000-000000000402',
            '00000000-0000-4000-8000-000000000403',
            '00000000-0000-4000-8000-000000000404',
            str_repeat('a', 64),
            null,
        );

        self::assertNull($reservation->getId());
        self::assertSame('00000000-0000-4000-8000-000000000401', $reservation->getReservationId());
        self::assertSame('00000000-0000-4000-8000-000000000402', $reservation->getStoreUuid());
        self::assertSame('00000000-0000-4000-8000-000000000403', $reservation->getTradeOrderUuid());
        self::assertSame('00000000-0000-4000-8000-000000000404', $reservation->getStoreOrderUuid());
        self::assertSame(str_repeat('a', 64), $reservation->getRequestHash());
        self::assertNull($reservation->getRejectionCode());
        self::assertNull($reservation->getRejectionReason());
        self::assertSame([], $reservation->getLines());
    }

    public function testStockRejectsDisablingNegativePolicyWhileBalanceNegative(): void
    {
        $material = new Material('neg-policy', 'Negative policy', Material::KIND_RAW, 'kg');
        $stock = new Stock('00000000-0000-4000-8000-000000000405', $material);
        $stock->adjustOnHand('-5.000000');
        self::assertSame('-5.000000', $stock->getAvailableQuantity());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Negative stock cannot be disabled while available quantity is negative.');
        $stock->setAllowNegativeStock(false);
    }

    public function testMaterialSettersRejectBlankRequiredFields(): void
    {
        $material = new Material('ok-code', 'Ok name', Material::KIND_RAW, 'kg');

        foreach ([
            static fn () => $material->setCode('   '),
            static fn () => $material->setName(''),
            static fn () => $material->setUnit("\t"),
        ] as $setter) {
            try {
                $setter();
                self::fail('Expected blank material field to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('is required.', $exception->getMessage());
            }
        }
    }
}
