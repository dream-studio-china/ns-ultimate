<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Entity\Material;
use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\MessageHandler\ReservationReleaseRequestedHandler;
use App\Inventory\Service\InventoryServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class ReservationReleaseRequestedHandlerIntegrationTest extends IntegrationWebTestCase
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
     * @return array{object, string, Material}
     */
    private function reservedScenario(string $reservationId): array
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryServiceInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000301';
        $material = new Material('release-integration', 'Release integration', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $inventory->adjustStock($storeUuid, $material->getUuid(), '5.000000', 'receipt');
        $inventory->reserve(
            $reservationId,
            $storeUuid,
            '00000000-0000-4000-8000-000000000302',
            '00000000-0000-4000-8000-000000000303',
            [['lineId' => '00000000-0000-4000-8000-000000000304', 'catalogReference' => $material->getCode(), 'quantity' => '2.000000']],
            new \DateTimeImmutable('+1 hour'),
        );

        return [$container, $storeUuid, $material];
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseEnvelope(string $eventId, string $reservationId, string $reason = 'cancelled'): array
    {
        return [
            'eventId' => $eventId,
            'type' => 'inventory.reservation.release.requested',
            'version' => 1,
            'aggregateId' => $reservationId,
            'payload' => [
                'reservationId' => $reservationId,
                'storeUuid' => '00000000-0000-4000-8000-000000000301',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000302',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000303',
                'reason' => $reason,
                'requestedAt' => '2026-07-26T00:01:00+00:00',
            ],
        ];
    }

    #[Group('low-value')]
    public function testReleaseForUnknownReservationThrows(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(ReservationReleaseRequestedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation was not found.');
        $handler(new ReservationReleaseRequestedMessage(
            $this->releaseEnvelope('00000000-0000-4000-8000-000000000310', '00000000-0000-4000-8000-000000000311'),
        ));
    }

    public function testReleaseMessageIsIdempotentForSameEventId(): void
    {
        [$container, $storeUuid, $material] = $this->reservedScenario('00000000-0000-4000-8000-000000000311');
        $inventory = $container->get(InventoryServiceInterface::class);
        $handler = $container->get(ReservationReleaseRequestedHandler::class);
        $envelope = $this->releaseEnvelope('00000000-0000-4000-8000-000000000312', '00000000-0000-4000-8000-000000000311');

        $handler(new ReservationReleaseRequestedMessage($envelope));
        $handler(new ReservationReleaseRequestedMessage($envelope));

        self::assertSame('5.000000', $inventory->getStockView($storeUuid, $material->getUuid())['availableQuantity']);

        $consumed = $container->get(EntityManagerInterface::class)
            ->getRepository(InventoryConsumedEvent::class)
            ->findBy(['eventId' => '00000000-0000-4000-8000-000000000312']);
        self::assertCount(1, $consumed);
    }

    public function testReleaseForAlreadyReleasedReservationIsSilentlyIgnored(): void
    {
        [$container, $storeUuid, $material] = $this->reservedScenario('00000000-0000-4000-8000-000000000313');
        $inventory = $container->get(InventoryServiceInterface::class);
        $handler = $container->get(ReservationReleaseRequestedHandler::class);

        $first = $this->releaseEnvelope('00000000-0000-4000-8000-000000000314', '00000000-0000-4000-8000-000000000313');
        $second = $this->releaseEnvelope('00000000-0000-4000-8000-000000000315', '00000000-0000-4000-8000-000000000313', 'double release');

        $handler(new ReservationReleaseRequestedMessage($first));
        $handler(new ReservationReleaseRequestedMessage($second));

        self::assertSame('5.000000', $inventory->getStockView($storeUuid, $material->getUuid())['availableQuantity']);
        $consumed = $container->get(EntityManagerInterface::class)
            ->getRepository(InventoryConsumedEvent::class)
            ->findBy(['eventId' => '00000000-0000-4000-8000-000000000315']);
        self::assertCount(1, $consumed);
    }
}
