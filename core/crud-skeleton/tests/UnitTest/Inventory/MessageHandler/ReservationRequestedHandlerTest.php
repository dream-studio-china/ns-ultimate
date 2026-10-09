<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Message\ReservationRequestedMessage;
use App\Inventory\MessageHandler\ReservationRequestedHandler;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use App\Inventory\Repository\LedgerEntryRepository;
use App\Inventory\Repository\ReservationRepository;
use App\Inventory\Repository\StockRepository;
use App\Inventory\Repository\MaterialRepository;
use App\Inventory\Repository\SpecificationRecipeRepository;
use App\Inventory\Service\InventoryOutboxService;
use App\Inventory\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReservationRequestedHandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validEnvelope(): array
    {
        return [
            'eventId' => '00000000-0000-4000-8000-000000000001',
            'type' => 'inventory.reservation.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000002',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000002',
                'storeUuid' => '00000000-0000-4000-8000-000000000003',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000004',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000005',
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000006',
                    'catalogReference' => '00000000-0000-4000-8000-000000000007',
                    'quantity' => '1.000000',
                ]],
                'requestedAt' => '2026-07-26T00:00:00+00:00',
                'expiresAt' => '2026-07-27T00:00:00+00:00',
            ],
        ];
    }

    private function unusedInventoryService(): InventoryService
    {
        return new InventoryService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MaterialRepository::class),
            $this->createMock(StockRepository::class),
            $this->createMock(SpecificationRecipeRepository::class),
            $this->createMock(ReservationRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            new InventoryOutboxService($this->createMock(EntityManagerInterface::class)),
        );
    }

    private function createHandler(
        ?InventoryConsumedEventRepository $consumed = null,
        ?InventoryService $service = null,
        ?EntityManagerInterface $em = null,
    ): ReservationRequestedHandler {
        return new ReservationRequestedHandler(
            $consumed ?? $this->createMock(InventoryConsumedEventRepository::class),
            $service ?? $this->unusedInventoryService(),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertRejected(array $envelope, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->createHandler()(new ReservationRequestedMessage($envelope));
    }

    public function testRejectsWrongTypeOrVersion(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['type'] = 'inventory.reservation.confirmed';

        $this->assertRejected($envelope, 'Invalid inventory.reservation.requested.v1 envelope type or version.');
    }

    public function testRejectsInvalidEnvelopeShape(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['eventId'] = 'not-a-uuid';

        $this->assertRejected($envelope, 'Invalid inventory.reservation.requested.v1 envelope.');
    }

    public function testRejectsMissingPayloadFields(): void
    {
        $envelope = $this->validEnvelope();
        unset($envelope['payload']['storeUuid']);

        $this->assertRejected($envelope, 'Invalid inventory reservation request payload.');
    }

    public function testRejectsEmptyItems(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['items'] = [];

        $this->assertRejected($envelope, 'Invalid inventory reservation request payload.');
    }

    public function testRejectsTimestampThatDoesNotMatchIso8601(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['requestedAt'] = 'not-a-date';

        $this->assertRejected($envelope, 'Reservation request timestamps must be ISO-8601 dates.');
    }

    public function testRejectsImpossibleCalendarDate(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['expiresAt'] = '2026-02-30T00:00:00+00:00';

        $this->assertRejected($envelope, 'Reservation request timestamps must be ISO-8601 dates.');
    }

    public function testRejectsExpiryBeforeOrEqualToRequestTime(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['requestedAt'] = '2026-07-26T00:00:00.123456+00:00';
        $envelope['payload']['expiresAt'] = '2026-07-26T00:00:00.123456+00:00';

        $this->assertRejected($envelope, 'Reservation expiry must be after its request time.');
    }

    public function testRejectsShortFractionalTimestampWithImpossibleDate(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['expiresAt'] = '2026-02-30T00:00:00.1+00:00';

        $this->assertRejected($envelope, 'Reservation request timestamps must be ISO-8601 dates.');
    }

    public function testRejectsItemWithInvalidCatalogReference(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['items'][0]['catalogReference'] = 'not-a-uuid';

        $this->assertRejected($envelope, 'Invalid inventory reservation request item.');
    }

    public function testRejectsItemWithNonPositiveQuantity(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['items'][0]['quantity'] = '0.000000';

        $this->assertRejected($envelope, 'Invalid inventory reservation request item.');
    }

    public function testRejectsDuplicateLineIds(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['items'][] = $envelope['payload']['items'][0];

        $this->assertRejected($envelope, 'Invalid inventory reservation request item.');
    }

    public function testSkipsEventAlreadyConsumedInsideTransaction(): void
    {
        $envelope = $this->validEnvelope();
        $payloadHash = hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
        $consumed = new InventoryConsumedEvent(
            $envelope['eventId'],
            'inventory.reservation.requested.v1',
            $envelope['payload']['reservationId'],
            $payloadHash,
        );

        $consumedRepo = $this->createMock(InventoryConsumedEventRepository::class);
        $consumedRepo->method('findOneByEventId')
            ->willReturnOnConsecutiveCalls(null, $consumed);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $em->expects($this->never())->method('persist');

        $handler = new ReservationRequestedHandler($consumedRepo, $this->unusedInventoryService(), $em);
        $handler(new ReservationRequestedMessage($envelope));

        $this->addToAssertionCount(1);
    }
}
