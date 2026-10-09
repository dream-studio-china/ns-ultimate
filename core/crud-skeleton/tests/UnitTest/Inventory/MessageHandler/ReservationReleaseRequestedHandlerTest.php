<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\MessageHandler\ReservationReleaseRequestedHandler;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use App\Inventory\Repository\LedgerEntryRepository;
use App\Inventory\Repository\ReservationRepository;
use App\Inventory\Repository\StockRepository;
use App\Inventory\Repository\MaterialRepository;
use App\Inventory\Repository\SpecificationRecipeRepository;
use App\Inventory\Service\InventoryMessageIntegrityException;
use App\Inventory\Service\InventoryOutboxService;
use App\Inventory\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReservationReleaseRequestedHandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validEnvelope(): array
    {
        return [
            'eventId' => '00000000-0000-4000-8000-000000000010',
            'type' => 'inventory.reservation.release.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000011',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000011',
                'storeUuid' => '00000000-0000-4000-8000-000000000012',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000013',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000014',
                'reason' => 'cancelled',
                'requestedAt' => '2026-07-26T00:01:00+00:00',
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
        ?ReservationRepository $reservations = null,
        ?InventoryService $service = null,
        ?EntityManagerInterface $em = null,
    ): ReservationReleaseRequestedHandler {
        return new ReservationReleaseRequestedHandler(
            $consumed ?? $this->createMock(InventoryConsumedEventRepository::class),
            $reservations ?? $this->createMock(ReservationRepository::class),
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
        $this->createHandler()(new ReservationReleaseRequestedMessage($envelope));
    }

    public function testRejectsWrongTypeOrVersion(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['type'] = 'inventory.reservation.release.confirmed';

        $this->assertRejected($envelope, 'Invalid inventory.reservation.release.requested.v1 envelope type or version.');
    }

    public function testRejectsInvalidEnvelopeShape(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['eventId'] = 'not-a-uuid';

        $this->assertRejected($envelope, 'Invalid inventory.reservation.release.requested.v1 envelope.');
    }

    public function testRejectsInvalidCorrelation(): void
    {
        $envelope = $this->validEnvelope();
        unset($envelope['payload']['storeUuid']);

        $this->assertRejected($envelope, 'Invalid inventory reservation release correlation.');
    }

    public function testRejectsEmptyReason(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['reason'] = '   ';

        $this->assertRejected($envelope, 'Invalid inventory reservation release payload.');
    }

    public function testRejectsMissingRequestedAt(): void
    {
        $envelope = $this->validEnvelope();
        unset($envelope['payload']['requestedAt']);

        $this->assertRejected($envelope, 'Invalid inventory reservation release payload.');
    }

    public function testRejectsTimestampThatDoesNotMatchIso8601(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['payload']['requestedAt'] = 'not-a-date';

        $this->assertRejected($envelope, 'Reservation release request time must be an ISO-8601 date.');
    }

    public function testRejectsEventIdReusedWithDifferentPayload(): void
    {
        $envelope = $this->validEnvelope();
        $consumed = new InventoryConsumedEvent(
            $envelope['eventId'],
            'inventory.reservation.release.requested.v1',
            $envelope['payload']['reservationId'],
            str_repeat('f', 64),
        );

        $consumedRepo = $this->createMock(InventoryConsumedEventRepository::class);
        $consumedRepo->method('findOneByEventId')->willReturn($consumed);

        $this->expectException(InventoryMessageIntegrityException::class);
        $this->expectExceptionMessage('Event ID was reused with a different payload.');
        $this->createHandler($consumedRepo)(new ReservationReleaseRequestedMessage($envelope));
    }

    public function testSkipsEventAlreadyConsumedInsideTransaction(): void
    {
        $envelope = $this->validEnvelope();
        $payloadHash = hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
        $consumed = new InventoryConsumedEvent(
            $envelope['eventId'],
            'inventory.reservation.release.requested.v1',
            $envelope['payload']['reservationId'],
            $payloadHash,
        );

        $consumedRepo = $this->createMock(InventoryConsumedEventRepository::class);
        $consumedRepo->method('findOneByEventId')
            ->willReturnOnConsecutiveCalls(null, $consumed);

        $reservations = $this->createMock(ReservationRepository::class);
        $reservations->expects($this->never())->method('findOneByReservationId');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $em->expects($this->never())->method('persist');

        $handler = $this->createHandler($consumedRepo, $reservations, null, $em);
        $handler(new ReservationReleaseRequestedMessage($envelope));

        $this->addToAssertionCount(1);
    }
}
