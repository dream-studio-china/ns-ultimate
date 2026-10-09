<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Trade\Command;


use PHPUnit\Framework\Attributes\Group;
use App\Trade\Command\PublishOutboxCommand;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Message\TradeOrderCancelledMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class PublishOutboxCommandTest extends TestCase
{
    private function makeMessage(string $topic, string $aggregateId = 'order-123', ?int $id = 1): TradeOutboxMessage
    {
        $message = new TradeOutboxMessage($topic, 'trade_order', $aggregateId, ['orderUuid' => $aggregateId]);
        if ($id !== null) {
            $property = new \ReflectionProperty(TradeOutboxMessage::class, 'id');
            $property->setValue($message, $id);
        }

        return $message;
    }

    private function runCommand(
        TradeOutboxMessageRepository $repo,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): array {
        $tester = new CommandTester(new PublishOutboxCommand($repo, $em, $bus));
        $exitCode = $tester->execute([]);

        return [$exitCode, $tester->getDisplay()];
    }

    public function testExecutePublishesNothingWhenNoMessages(): void
    {
        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([]);
        $repo->expects(self::never())->method('claim');
        $repo->expects(self::never())->method('defer');
        $bus->expects(self::never())->method('dispatch');
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $display);
    }

    public function testExecutePublishesCreatedMessageWithFullEnvelope(): void
    {
        $message = $this->makeMessage('trade.order.created.v1');

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->expects(self::once())
            ->method('claim')
            ->with(1, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(true);
        $repo->expects(self::never())->method('defer');

        $captured = null;
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TradeOrderCreatedMessage::class))
            ->willReturnCallback(static function (object $msg) use (&$captured): Envelope {
                $captured = $msg;

                return Envelope::wrap($msg);
            });
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 1 Trade outbox message(s).', $display);
        self::assertInstanceOf(TradeOrderCreatedMessage::class, $captured);
        self::assertNotNull($message->getPublishedAt());

        $envelope = $captured->envelope;
        self::assertSame($message->getEventId(), $envelope['eventId']);
        self::assertSame('trade.order.created', $envelope['type']);
        self::assertSame(1, $envelope['version']);
        self::assertSame($message->getOccurredAt()->format(DATE_ATOM), $envelope['occurredAt']);
        self::assertSame('trade_order', $envelope['aggregateType']);
        self::assertSame('order-123', $envelope['aggregateId']);
        self::assertSame('order-123', $envelope['correlationId']);
        self::assertNull($envelope['causationId']);
        self::assertSame($message->getPayload(), $envelope['payload']);
    }

    public function testExecutePublishesCancelledMessage(): void
    {
        $message = $this->makeMessage('trade.order.cancelled.v1', 'order-777');

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->method('claim')->willReturn(true);

        $captured = null;
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TradeOrderCancelledMessage::class))
            ->willReturnCallback(static function (object $msg) use (&$captured): Envelope {
                $captured = $msg;

                return Envelope::wrap($msg);
            });
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 1 Trade outbox message(s).', $display);
        self::assertInstanceOf(TradeOrderCancelledMessage::class, $captured);
        self::assertSame('trade.order.cancelled', $captured->envelope['type']);
        self::assertSame($message->getEventId(), $captured->envelope['eventId']);
    }

    public function testExecuteSkipsMessageWhenClaimFails(): void
    {
        $message = $this->makeMessage('trade.order.created.v1');

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->method('claim')->willReturn(false);
        $repo->expects(self::never())->method('defer');
        $bus->expects(self::never())->method('dispatch');
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $display);
        self::assertNull($message->getPublishedAt());
    }

    public function testExecuteSkipsMessageWhenIdIsNull(): void
    {
        $message = $this->makeMessage('trade.order.created.v1', 'order-123', null);

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->expects(self::never())->method('claim');
        $repo->expects(self::never())->method('defer');
        $bus->expects(self::never())->method('dispatch');
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $display);
    }

    public function testExecuteDefersUnsupportedTopic(): void
    {
        $message = $this->makeMessage('trade.custom.event.v1');

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->method('claim')->willReturn(true);
        $repo->expects(self::once())
            ->method('defer')
            ->with(
                1,
                'Unsupported Trade outbox topic: trade.custom.event.v1',
                self::callback(static fn (\DateTimeImmutable $at): bool => $at > new \DateTimeImmutable()),
            );
        $bus->expects(self::never())->method('dispatch');
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $display);
    }

    public function testExecuteDefersWhenDispatchThrows(): void
    {
        $message = $this->makeMessage('trade.order.created.v1');

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$message]);
        $repo->method('claim')->willReturn(true);
        $repo->expects(self::once())
            ->method('defer')
            ->with(
                1,
                'Messenger bus exploded',
                self::callback(static fn (\DateTimeImmutable $at): bool => $at > new \DateTimeImmutable()),
            );
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Messenger bus exploded'));
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $display);
        self::assertNull($message->getPublishedAt());
    }

    public function testExecuteHandlesMixedMessagesInSingleRun(): void
    {
        $created = $this->makeMessage('trade.order.created.v1', 'order-a', 1);
        $cancelled = $this->makeMessage('trade.order.cancelled.v1', 'order-b', 2);
        $unsupported = $this->makeMessage('trade.unknown.v1', 'order-c', 3);
        $alreadyClaimed = $this->makeMessage('trade.order.created.v1', 'order-d', 4);

        $repo = $this->createMock(TradeOutboxMessageRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repo->method('findUnpublished')->willReturn([$created, $unsupported, $alreadyClaimed, $cancelled]);
        $repo->method('claim')
            ->willReturnCallback(static fn (int $id): bool => $id !== 4);
        $repo->expects(self::once())
            ->method('defer')
            ->with(
                3,
                'Unsupported Trade outbox topic: trade.unknown.v1',
                self::isInstanceOf(\DateTimeImmutable::class),
            );

        $dispatched = [];
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return Envelope::wrap($msg);
            });
        $em->expects(self::once())->method('flush');

        [$exitCode, $display] = $this->runCommand($repo, $em, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 2 Trade outbox message(s).', $display);
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(TradeOrderCreatedMessage::class, $dispatched[0]);
        self::assertInstanceOf(TradeOrderCancelledMessage::class, $dispatched[1]);
        self::assertNotNull($created->getPublishedAt());
        self::assertNotNull($cancelled->getPublishedAt());
        self::assertNull($unsupported->getPublishedAt());
        self::assertNull($alreadyClaimed->getPublishedAt());
    }
}
