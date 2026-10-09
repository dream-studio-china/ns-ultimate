<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Command;

use App\Inventory\Command\PublishOutboxCommand;
use App\Inventory\Message\ReservationConfirmedMessage;
use App\Inventory\Message\ReservationRejectedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class PublishOutboxCommandTest extends TestCase
{
    private function message(int $id, string $topic): array
    {
        return [
            'id' => $id,
            'eventId' => '00000000-0000-4000-8000-0000000004' . $id . '0',
            'topic' => $topic,
            'aggregateId' => '00000000-0000-4000-8000-0000000004' . $id . '1',
            'payload' => ['reservationId' => '00000000-0000-4000-8000-0000000004' . $id . '1'],
        ];
    }

    private function runCommand(InventoryOutboxMessageRepository $repository, MessageBusInterface $bus): string
    {
        $output = new BufferedOutput();
        $command = new PublishOutboxCommand($repository, $bus);
        self::assertSame(0, $command->run(new ArrayInput([]), $output));

        return rtrim($output->fetch());
    }

    public function testSkipsMessageWhenClaimFails(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(1, 'inventory.reservation.confirmed.v1'),
        ]);
        $repository->expects(self::once())->method('claim')->willReturn(false);
        $repository->expects(self::never())->method('recordAttempt');
        $repository->expects(self::never())->method('markPublished');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 0 Inventory outbox message(s).', $output);
    }

    public function testPublishesConfirmedMessageAndMarksPublished(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(2, 'inventory.reservation.confirmed.v1'),
        ]);
        $repository->method('claim')->willReturn(true);
        $repository->expects(self::once())->method('markPublished')->with(2);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static fn (object $m) => $m instanceof ReservationConfirmedMessage),
        )->willReturn(new Envelope(new \stdClass()));

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 1 Inventory outbox message(s).', $output);
    }

    public function testPublishesRejectedMessageAndMarksPublished(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(5, 'inventory.reservation.rejected.v1'),
        ]);
        $repository->method('claim')->willReturn(true);
        $repository->expects(self::once())->method('markPublished')->with(5);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static fn (object $m) => $m instanceof ReservationRejectedMessage),
        )->willReturn(new Envelope(new \stdClass()));

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 1 Inventory outbox message(s).', $output);
    }

    public function testPublishesReleasedMessageAndMarksPublished(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(6, 'inventory.reservation.released.v1'),
        ]);
        $repository->method('claim')->willReturn(true);
        $repository->expects(self::once())->method('markPublished')->with(6);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static fn (object $m) => $m instanceof ReservationReleasedMessage),
        )->willReturn(new Envelope(new \stdClass()));

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 1 Inventory outbox message(s).', $output);
    }

    public function testDefersUnsupportedTopic(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(3, 'inventory.unsupported.v1'),
        ]);
        $repository->method('claim')->willReturn(true);
        $repository->expects(self::once())->method('recordAttempt')->with(
            3,
            'Unsupported Inventory outbox topic: inventory.unsupported.v1',
            self::anything(),
        );
        $repository->expects(self::never())->method('markPublished');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 0 Inventory outbox message(s).', $output);
    }

    public function testRecordsAttemptWhenDispatchFails(): void
    {
        $repository = $this->createMock(InventoryOutboxMessageRepository::class);
        $repository->method('findUnpublishedForPublishing')->willReturn([
            $this->message(4, 'inventory.reservation.released.v1'),
        ]);
        $repository->method('claim')->willReturn(true);
        $repository->expects(self::once())->method('recordAttempt')->with(
            4,
            'transport unavailable',
            self::anything(),
        );
        $repository->expects(self::never())->method('markPublished');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport unavailable'));

        $output = $this->runCommand($repository, $bus);
        self::assertSame('Published 0 Inventory outbox message(s).', $output);
    }
}
