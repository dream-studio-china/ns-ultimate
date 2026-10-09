<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use App\Inventory\Service\InventoryServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class InventoryServiceTest extends IntegrationWebTestCase
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
            $em->createQuery('DELETE FROM '.$entity.' entity')->execute();
        }
        self::ensureKernelShutdown();
    }

    public function testVirtualZeroStockDoesNotPersistAndNegativePolicyCanCreateBalance(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $material = new Material('finished-zero', 'Finished zero', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $service = $client->getContainer()->get(InventoryServiceInterface::class);

        $view = $service->getStockView('00000000-0000-4000-8000-000000000001', $material->getUuid());
        self::assertFalse($view['exists']);
        self::assertSame('0.000000', $view['availableQuantity']);

        $stock = $service->setStockAllowNegative('00000000-0000-4000-8000-000000000001', $material->getUuid(), true);
        self::assertTrue($stock->allowsNegativeStock());
    }

    public function testRecipeReservationAggregatesMaterialDemandAndReleaseIsIdempotent(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000001';
        $specUuid = '00000000-0000-4000-8000-000000000002';
        $material = new Material('flour', 'Flour', Material::KIND_RAW, 'kg');
        $recipe = new SpecificationRecipe($specUuid);
        $recipe->addLine(new RecipeLine($material, '1.500000'));
        $em->persist($material);
        $em->persist($recipe);
        $em->flush();

        $service = $container->get(InventoryServiceInterface::class);
        $service->adjustStock($storeUuid, $material->getUuid(), '10.000000', 'receipt', 'receipt-1');
        $reservation = $service->reserve(
            '00000000-0000-4000-8000-000000000003',
            $storeUuid,
            '00000000-0000-4000-8000-000000000004',
            '00000000-0000-4000-8000-000000000005',
            [['lineId' => '00000000-0000-4000-8000-000000000006', 'catalogReference' => $specUuid, 'quantity' => '2.000000']],
        );
        self::assertSame('confirmed', $reservation->getStatus());
        self::assertSame('7.000000', $service->getStockView($storeUuid, $material->getUuid())['availableQuantity']);

        $service->release($reservation->getReservationId(), 'cancelled');
        $service->release($reservation->getReservationId(), 'cancelled');
        self::assertSame('10.000000', $service->getStockView($storeUuid, $material->getUuid())['availableQuantity']);
        self::assertCount(2, $container->get(InventoryOutboxMessageRepository::class)->findUnpublished());
    }

    public function testReservationRejectsExpiredAndUnstockableRequests(): void
    {
        $client = static::createClient();
        $service = $client->getContainer()->get(InventoryServiceInterface::class);

        $expired = $service->reserve(
            '00000000-0000-4000-8000-000000000031',
            '00000000-0000-4000-8000-000000000032',
            '00000000-0000-4000-8000-000000000033',
            '00000000-0000-4000-8000-000000000034',
            [['lineId' => '00000000-0000-4000-8000-000000000035', 'catalogReference' => '00000000-0000-4000-8000-000000000036', 'quantity' => '1.000000']],
            new \DateTimeImmutable('-1 minute'),
        );
        self::assertSame('rejected', $expired->getStatus());
        self::assertSame('RESERVATION_EXPIRED', $expired->getRejectionCode());

        $unstockable = $service->reserve(
            '00000000-0000-4000-8000-000000000037',
            '00000000-0000-4000-8000-000000000032',
            '00000000-0000-4000-8000-000000000038',
            '00000000-0000-4000-8000-000000000039',
            [['lineId' => '00000000-0000-4000-8000-000000000040', 'catalogReference' => '00000000-0000-4000-8000-000000000041', 'quantity' => '1.000000']],
        );
        self::assertSame('SPECIFICATION_NOT_STOCKABLE', $unstockable->getRejectionCode());
    }

    public function testReservationRequestConflictAndInactiveAdjustmentAreRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $material = new Material('inactive', 'Inactive', Material::KIND_FINISHED, 'piece');
        $material->setStatus(Material::STATUS_INACTIVE);
        $em->persist($material);
        $em->flush();
        $service = $client->getContainer()->get(InventoryServiceInterface::class);

        $this->expectException(\LogicException::class);
        $service->adjustStock('00000000-0000-4000-8000-000000000042', $material->getUuid(), '1.000000', 'test');
    }

    public function testStockAndReservationFailurePathsAreExplicit(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $material = new Material('limited', 'Limited', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $service = $container->get(InventoryServiceInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000051';

        $rejected = $service->reserve(
            '00000000-0000-4000-8000-000000000052',
            $storeUuid,
            '00000000-0000-4000-8000-000000000053',
            '00000000-0000-4000-8000-000000000054',
            [['lineId' => '00000000-0000-4000-8000-000000000055', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']],
        );
        self::assertSame('OUT_OF_STOCK', $rejected->getRejectionCode());
        self::assertSame($rejected, $service->reserve(
            '00000000-0000-4000-8000-000000000052',
            $storeUuid,
            '00000000-0000-4000-8000-000000000053',
            '00000000-0000-4000-8000-000000000054',
            [['lineId' => '00000000-0000-4000-8000-000000000055', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']],
        ));

        $this->expectException(\App\Inventory\Service\ReservationConflictException::class);
        $service->reserve(
            '00000000-0000-4000-8000-000000000052',
            $storeUuid,
            '00000000-0000-4000-8000-000000000053',
            '00000000-0000-4000-8000-000000000054',
            [['lineId' => '00000000-0000-4000-8000-000000000056', 'catalogReference' => $material->getCode(), 'quantity' => '2.000000']],
        );
    }

    public function testUnknownReservationAndStockViewsFailExplicitly(): void
    {
        $client = static::createClient();
        $service = $client->getContainer()->get(InventoryServiceInterface::class);
        try {
            $service->release('00000000-0000-4000-8000-000000000081');
            self::fail('Expected unknown reservation rejection.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $service->getStockView('00000000-0000-4000-8000-000000000082', '00000000-0000-4000-8000-000000000083');
            self::fail('Expected unknown material rejection.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testStockPoliciesAndAdjustmentIdempotencyAreScopedToStore(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $service = $client->getContainer()->get(InventoryServiceInterface::class);
        $material = new Material('policy', 'Policy', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $firstStore = '00000000-0000-4000-8000-000000000091';
        $secondStore = '00000000-0000-4000-8000-000000000092';

        $service->setStockAllowNegative($firstStore, $material->getUuid(), true);
        $service->adjustStock($firstStore, $material->getUuid(), '-1.000000', 'correction', 'same-reference');
        try {
            $service->setStockAllowNegative($firstStore, $material->getUuid(), false);
            self::fail('Expected negative policy disable rejection.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }

        $service->adjustStock($secondStore, $material->getUuid(), '1.000000', 'receipt', 'same-reference');
        self::assertSame('1.000000', $service->getStockView($secondStore, $material->getUuid())['onHandQuantity']);
    }

    public function testServiceRejectsInvalidStoreUuidAtEveryStoreBoundary(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $service = $client->getContainer()->get(InventoryServiceInterface::class);
        $material = new Material('uuid-boundary', 'UUID boundary', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();

        foreach ([
            fn () => $service->getStockView('not-a-uuid', $material->getUuid()),
            fn () => $service->setStockAllowNegative('not-a-uuid', $material->getUuid(), true),
            fn () => $service->adjustStock('not-a-uuid', $material->getUuid(), '1.000000', 'receipt'),
            fn () => $service->reserve('00000000-0000-4000-8000-000000000093', 'not-a-uuid', '00000000-0000-4000-8000-000000000094', '00000000-0000-4000-8000-000000000095', [['lineId' => '00000000-0000-4000-8000-000000000096', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected invalid store UUID rejection.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testExpiredConfirmedReservationsAreReleasedIdempotently(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $service = $container->get(InventoryServiceInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000097';
        $material = new Material('expiring', 'Expiring', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $service->adjustStock($storeUuid, $material->getUuid(), '2.000000', 'receipt');
        $reservation = $service->reserve('00000000-0000-4000-8000-000000000098', $storeUuid, '00000000-0000-4000-8000-000000000099', '00000000-0000-4000-8000-000000000100', [['lineId' => '00000000-0000-4000-8000-000000000101', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']], new \DateTimeImmutable('+1 hour'));
        $em->createQuery('UPDATE App\\Inventory\\Entity\\Reservation reservation SET reservation.expiresAt = :expired WHERE reservation.reservationId = :reservationId')
            ->setParameter('expired', new \DateTimeImmutable('-1 minute'))
            ->setParameter('reservationId', $reservation->getReservationId())
            ->execute();
        $em->clear();

        $output = new BufferedOutput();
        $command = new \App\Inventory\Command\ReleaseExpiredReservationsCommand($service);
        self::assertSame(0, $command->run(new ArrayInput([]), $output));
        self::assertStringContainsString('Released 1 expired Inventory reservation(s).', $output->fetch());
        self::assertSame(0, $command->run(new ArrayInput([]), new BufferedOutput()));
        self::assertSame('2.000000', $service->getStockView($storeUuid, $material->getUuid())['availableQuantity']);
        self::assertNotNull($container->get(InventoryOutboxMessageRepository::class)->findOneBy(['topic' => 'inventory.reservation.released.v1']));
    }
}
