<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Message\ReservationRequestedMessage;
use App\Inventory\MessageHandler\ReservationRequestedHandler;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use App\Inventory\Repository\ReservationRepository;
use App\Inventory\Service\ReservationConflictException;
use App\Inventory\Service\InventoryServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class ReservationRequestedHandlerIntegrationTest extends IntegrationWebTestCase
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides = []): array
    {
        return array_replace([
            'eventId' => '00000000-0000-4000-8000-000000000401',
            'type' => 'inventory.reservation.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000402',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000402',
                'storeUuid' => '00000000-0000-4000-8000-000000000403',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000404',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000405',
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000406',
                    'catalogReference' => '00000000-0000-4000-8000-000000000407',
                    'quantity' => '1.000000',
                ]],
                'requestedAt' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
                'expiresAt' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
            ],
        ], $overrides);
    }

    #[Group('low-value')]
    public function testRecipeResolutionFailureRejectsReservation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $active = new Material('recipe-active', 'Recipe active', Material::KIND_RAW, 'kg');
        $inactive = new Material('recipe-inactive', 'Recipe inactive', Material::KIND_RAW, 'kg');
        $inactive->setStatus(Material::STATUS_INACTIVE);
        $recipe = new SpecificationRecipe('00000000-0000-4000-8000-000000000407');
        $recipe->addLine(new RecipeLine($active, '1.000000'));
        $recipe->addLine(new RecipeLine($inactive, '1.000000'));
        $em->persist($active);
        $em->persist($inactive);
        $em->persist($recipe);
        $em->flush();

        $handler = $container->get(ReservationRequestedHandler::class);
        $handler(new ReservationRequestedMessage($this->envelope()));

        $reservation = $container->get(ReservationRepository::class)
            ->findOneByReservationId('00000000-0000-4000-8000-000000000402');
        self::assertNotNull($reservation);
        self::assertSame('rejected', $reservation->getStatus());
        self::assertSame('MATERIAL_INACTIVE', $reservation->getRejectionCode());
    }

    #[Group('low-value')]
    public function testMissingSpecificationRejectsReservation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $envelope = $this->envelope([
            'eventId' => '00000000-0000-4000-8000-000000000421',
            'aggregateId' => '00000000-0000-4000-8000-000000000422',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000422',
                'storeUuid' => '00000000-0000-4000-8000-000000000403',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000404',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000405',
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000406',
                    'catalogReference' => '00000000-0000-4000-8000-000000000408',
                    'quantity' => '1.000000',
                ]],
                'requestedAt' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
                'expiresAt' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
            ],
        ]);

        $handler = $container->get(ReservationRequestedHandler::class);
        $handler(new ReservationRequestedMessage($envelope));

        $reservation = $container->get(ReservationRepository::class)
            ->findOneByReservationId('00000000-0000-4000-8000-000000000422');
        self::assertNotNull($reservation);
        self::assertSame('rejected', $reservation->getStatus());
        self::assertSame('SPECIFICATION_NOT_STOCKABLE', $reservation->getRejectionCode());
    }

    public function testServiceExceptionPropagatesAndConsumedEventIsRolledBack(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryServiceInterface::class);
        $material = new Material('conflict-material', 'Conflict material', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $inventory->reserve(
            '00000000-0000-4000-8000-000000000409',
            '00000000-0000-4000-8000-000000000410',
            '00000000-0000-4000-8000-000000000411',
            '00000000-0000-4000-8000-000000000412',
            [['lineId' => '00000000-0000-4000-8000-000000000413', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']],
            new \DateTimeImmutable('+1 hour'),
        );

        $envelope = $this->envelope([
            'eventId' => '00000000-0000-4000-8000-000000000414',
            'aggregateId' => '00000000-0000-4000-8000-000000000409',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000409',
                'storeUuid' => '00000000-0000-4000-8000-000000000410',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000411',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000412',
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000415',
                    'catalogReference' => $material->getUuid(),
                    'quantity' => '2.000000',
                ]],
                'requestedAt' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
                'expiresAt' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
            ],
        ]);

        $handler = $container->get(ReservationRequestedHandler::class);
        try {
            $handler(new ReservationRequestedMessage($envelope));
            self::fail('Expected reservation conflict to propagate.');
        } catch (ReservationConflictException) {
            self::assertTrue(true);
        }

        self::ensureKernelShutdown();
        $freshClient = static::createClient();
        $freshEm = $freshClient->getContainer()->get(EntityManagerInterface::class);
        self::assertNull(
            $freshEm->getRepository(InventoryConsumedEvent::class)
                ->findOneBy(['eventId' => '00000000-0000-4000-8000-000000000414']),
        );
    }
}
