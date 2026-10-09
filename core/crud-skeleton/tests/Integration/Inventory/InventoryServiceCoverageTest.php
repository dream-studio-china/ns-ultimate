<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Entity\Reservation;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\ReservationLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Service\ReservationConflictException;
use App\Inventory\Service\InventoryServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class InventoryServiceCoverageTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ([
            'App\\Inventory\\Entity\\InventoryOutboxMessage',
            'App\\Inventory\\Entity\\InventoryConsumedEvent',
            'App\\Inventory\\Entity\\LedgerEntry',
            'App\\Inventory\\Entity\\ReservationLine',
            'App\\Inventory\\Entity\\Reservation',
            'App\\Inventory\\Entity\\RecipeLine',
            'App\\Inventory\\Entity\\SpecificationRecipe',
            'App\\Inventory\\Entity\\Stock',
            'App\\Inventory\\Entity\\Material',
        ] as $entity) {
            $em->createQuery('DELETE FROM ' . $entity . ' entity')->execute();
        }
        self::ensureKernelShutdown();
    }

    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $clientInstance = null;

    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        if ($this->clientInstance === null) {
            $this->clientInstance = static::createClient();
        }

        return $this->clientInstance;
    }

    private function inventory(): InventoryServiceInterface
    {
        return $this->client()->getContainer()->get(InventoryServiceInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        return $this->client()->getContainer()->get(EntityManagerInterface::class);
    }

    private function material(string $code, string $kind = Material::KIND_FINISHED, bool $active = true): Material
    {
        $em = $this->em();
        $material = new Material($code, ucfirst($code), $kind, 'piece');
        if (!$active) {
            $material->setStatus(Material::STATUS_INACTIVE);
        }
        $em->persist($material);
        $em->flush();

        return $material;
    }

    private function reserveItem(string $catalogReference, string $quantity = '1.000000', string $lineId = '00000000-0000-4000-8000-000000000099'): array
    {
        return ['lineId' => $lineId, 'catalogReference' => $catalogReference, 'quantity' => $quantity];
    }

    public function testAdjustStockRejectsZeroQuantityOrEmptyReason(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000501';

        foreach ([
            fn () => $service->adjustStock($store, '00000000-0000-4000-8000-000000000502', '0.000000', 'receipt'),
            fn () => $service->adjustStock($store, '00000000-0000-4000-8000-000000000502', '1.000000', '   '),
        ] as $call) {
            try {
                $call();
                self::fail('Expected zero/blank adjustment rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Adjustment quantity and reason are required.', $exception->getMessage());
            }
        }
    }

    #[Group('low-value')]
    public function testAdjustStockIsIdempotentForRepeatedReference(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000511';
        $material = $this->material('idem', Material::KIND_FINISHED);

        $first = $service->adjustStock($store, $material->getUuid(), '5.000000', 'receipt', 'ref-1');
        $second = $service->adjustStock($store, $material->getUuid(), '3.000000', 'receipt', 'ref-1');

        self::assertSame($first, $second);
        self::assertSame('5.000000', $service->getStockView($store, $material->getUuid())['onHandQuantity']);
    }

    public function testAdjustStockWithLedgerButMissingStockBalanceFails(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000521';
        $material = $this->material('orphan-ledger', Material::KIND_FINISHED);

        $service->adjustStock($store, $material->getUuid(), '5.000000', 'receipt', 'ref-2');

        $em = $this->em();
        $em->createQuery('DELETE FROM App\\Inventory\\Entity\\Stock stock WHERE stock.material = :material')
            ->setParameter('material', $material)
            ->execute();
        $em->clear();

        try {
            $service->adjustStock($store, $material->getUuid(), '1.000000', 'receipt', 'ref-2');
            self::fail('Expected orphan ledger rejection.');
        } catch (\LogicException $exception) {
            self::assertSame('Adjustment ledger does not have a stock balance.', $exception->getMessage());
        }
    }

    public function testAdjustStockAppliesNegativePolicyToExistingStock(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000531';
        $material = $this->material('policy-on-adjust', Material::KIND_FINISHED);

        $service->adjustStock($store, $material->getUuid(), '1.000000', 'receipt');
        $stock = $service->adjustStock($store, $material->getUuid(), '1.000000', 'receipt', null, null, true);

        self::assertTrue($stock->allowsNegativeStock());
    }

    public function testAdjustStockBelowConfirmedReservationsIsRejected(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000541';
        $material = $this->material('below-reserved', Material::KIND_FINISHED);

        $service->adjustStock($store, $material->getUuid(), '2.000000', 'receipt');
        $reservation = $service->reserve(
            '00000000-0000-4000-8000-000000000542',
            $store,
            '00000000-0000-4000-8000-000000000543',
            '00000000-0000-4000-8000-000000000544',
            [$this->reserveItem($material->getCode(), '2.000000')],
        );
        self::assertSame('confirmed', $reservation->getStatus());

        try {
            $service->adjustStock($store, $material->getUuid(), '-1.000000', 'correction');
            self::fail('Expected below-reserved adjustment rejection.');
        } catch (\LogicException $exception) {
            self::assertSame('Adjustment would make confirmed reservations unavailable.', $exception->getMessage());
        }
    }

    public function testSetStockAllowNegativeRejectsMissingMaterialInBothBranches(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000551';

        try {
            $service->setStockAllowNegative($store, '00000000-0000-4000-8000-000000000552', false);
            self::fail('Expected missing material rejection (pre-check branch).');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Material was not found.', $exception->getMessage());
        }

        try {
            $service->setStockAllowNegative($store, '00000000-0000-4000-8000-000000000553', true);
            self::fail('Expected missing material rejection (transaction branch).');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Material was not found.', $exception->getMessage());
        }
    }

    public function testSetStockAllowNegativeUpdatesExistingStock(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000561';
        $material = $this->material('existing-policy', Material::KIND_FINISHED);

        $service->adjustStock($store, $material->getUuid(), '1.000000', 'receipt');
        $stock = $service->setStockAllowNegative($store, $material->getUuid(), true);

        self::assertTrue($stock->allowsNegativeStock());
    }

    public function testReserveRejectsStoreOrderAlreadyReserved(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000571';
        $material = $this->material('double-store-order', Material::KIND_FINISHED);
        $service->adjustStock($store, $material->getUuid(), '1.000000', 'receipt');

        $service->reserve(
            '00000000-0000-4000-8000-000000000572',
            $store,
            '00000000-0000-4000-8000-000000000573',
            '00000000-0000-4000-8000-000000000574',
            [$this->reserveItem($material->getCode())],
        );

        $this->expectException(ReservationConflictException::class);
        $this->expectExceptionMessage('Store order already has a reservation.');
        $service->reserve(
            '00000000-0000-4000-8000-000000000575',
            $store,
            '00000000-0000-4000-8000-000000000576',
            '00000000-0000-4000-8000-000000000574',
            [$this->reserveItem($material->getCode())],
        );
    }

    public function testReleaseRejectsReservationWithMissingMaterial(): void
    {
        $service = $this->inventory();
        $em = $this->em();
        $store = '00000000-0000-4000-8000-000000000581';
        $ghost = new Material('ghost-material', 'Ghost material', Material::KIND_FINISHED, 'piece');

        $reservation = new Reservation(
            '00000000-0000-4000-8000-000000000582',
            $store,
            '00000000-0000-4000-8000-000000000583',
            '00000000-0000-4000-8000-000000000584',
            hash('sha256', 'release-missing-material'),
            null,
        );
        $reservation->addLine(new ReservationLine($ghost, '1.000000', ['spec']));
        $reservation->confirm();
        $em->persist($reservation);
        $em->flush();

        try {
            $service->release('00000000-0000-4000-8000-000000000582');
            self::fail('Expected missing reservation material rejection.');
        } catch (\LogicException $exception) {
            self::assertSame('Reservation material was not found.', $exception->getMessage());
        }
    }

    public function testReleaseRejectsReservationWithMissingStock(): void
    {
        $service = $this->inventory();
        $em = $this->em();
        $store = '00000000-0000-4000-8000-000000000591';
        $material = $this->material('missing-stock', Material::KIND_FINISHED);

        $reservation = new Reservation(
            '00000000-0000-4000-8000-000000000592',
            $store,
            '00000000-0000-4000-8000-000000000593',
            '00000000-0000-4000-8000-000000000594',
            hash('sha256', 'release-missing-stock'),
            null,
        );
        $reservation->addLine(new ReservationLine($material, '1.000000', ['spec']));
        $reservation->confirm();
        $em->persist($reservation);
        $em->flush();

        try {
            $service->release('00000000-0000-4000-8000-000000000592');
            self::fail('Expected missing reservation stock rejection.');
        } catch (\LogicException $exception) {
            self::assertSame('Reservation stock was not found.', $exception->getMessage());
        }
    }

    public function testReserveRejectsEmptyItemList(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000601';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation requires at least one item.');
        $service->reserve(
            '00000000-0000-4000-8000-000000000602',
            $store,
            '00000000-0000-4000-8000-000000000603',
            '00000000-0000-4000-8000-000000000604',
            [],
        );
    }

    public function testReserveRejectsDuplicateLineIds(): void
    {
        $service = $this->inventory();
        $store = '00000000-0000-4000-8000-000000000611';
        $material = $this->material('dup-line', Material::KIND_FINISHED);

        $line = $this->reserveItem($material->getCode());
        $line['lineId'] = '00000000-0000-4000-8000-000000000615';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation items require unique line IDs.');
        $service->reserve(
            '00000000-0000-4000-8000-000000000612',
            $store,
            '00000000-0000-4000-8000-000000000613',
            '00000000-0000-4000-8000-000000000614',
            [$line, $line],
        );
    }

    #[Group('low-value')]
    public function testReserveRejectsInactiveRecipeMaterial(): void
    {
        $service = $this->inventory();
        $em = $this->em();
        $store = '00000000-0000-4000-8000-000000000621';
        $inactive = new Material('inactive-recipe-material', 'Inactive recipe material', Material::KIND_RAW, 'kg');
        $inactive->setStatus(Material::STATUS_INACTIVE);
        $em->persist($inactive);
        $recipe = new SpecificationRecipe('00000000-0000-4000-8000-000000000622');
        $recipe->addLine(new RecipeLine($inactive, '1.000000'));
        $em->persist($recipe);
        $em->flush();

        $reservation = $service->reserve(
            '00000000-0000-4000-8000-000000000623',
            $store,
            '00000000-0000-4000-8000-000000000624',
            '00000000-0000-4000-8000-000000000625',
            [$this->reserveItem('00000000-0000-4000-8000-000000000622')],
        );

        self::assertSame('rejected', $reservation->getStatus());
        self::assertSame('MATERIAL_INACTIVE', $reservation->getRejectionCode());
    }
}
