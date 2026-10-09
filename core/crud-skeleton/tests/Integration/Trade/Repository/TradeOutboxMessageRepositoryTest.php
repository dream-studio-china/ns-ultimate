<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade\Repository;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Trade\Command\PublishOutboxCommand;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Message\TradeOrderCancelledMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class TradeOutboxMessageRepositoryTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private TradeOutboxMessageRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Trade\\Entity\\TradeOutboxMessage message')->execute();

        /** @var TradeOutboxMessageRepository $repo */
        $repo = $this->em->getRepository(TradeOutboxMessage::class);
        $this->repo = $repo;
    }

    private function createMessage(
        string $topic = 'trade.order.created.v1',
        string $aggregateId = 'agg-1',
    ): TradeOutboxMessage {
        $message = new TradeOutboxMessage($topic, 'trade_order', $aggregateId, ['orderUuid' => $aggregateId]);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function setAvailableAt(int $id, \DateTimeImmutable $at): void
    {
        $this->em->createQuery(
            'UPDATE App\\Trade\\Entity\\TradeOutboxMessage m SET m.availableAt = :at WHERE m.id = :id',
        )
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->execute();
    }

    private function setPublishedAt(int $id): void
    {
        $this->em->createQuery(
            'UPDATE App\\Trade\\Entity\\TradeOutboxMessage m SET m.publishedAt = :at WHERE m.id = :id',
        )
            ->setParameter('at', new \DateTimeImmutable())
            ->setParameter('id', $id)
            ->execute();
    }

    public function testFindUnpublishedReturnsOnlyUnpublishedAndAvailableInIdOrder(): void
    {
        $first = $this->createMessage('trade.order.created.v1', 'agg-1');
        $second = $this->createMessage('trade.order.created.v1', 'agg-2');
        $published = $this->createMessage('trade.order.created.v1', 'agg-3');
        $future = $this->createMessage('trade.order.created.v1', 'agg-4');
        $this->setPublishedAt((int) $published->getId());
        $this->setAvailableAt((int) $future->getId(), new \DateTimeImmutable('+10 minutes'));
        $this->em->clear();

        $rows = $this->repo->findUnpublished();

        $ids = array_map(static fn (TradeOutboxMessage $m): int => (int) $m->getId(), $rows);
        self::assertSame(
            [(int) $first->getId(), (int) $second->getId()],
            $ids,
        );
    }

    public function testFindUnpublishedRespectsLimit(): void
    {
        $this->createMessage('trade.order.created.v1', 'agg-1');
        $this->createMessage('trade.order.created.v1', 'agg-2');
        $this->createMessage('trade.order.created.v1', 'agg-3');
        $this->em->clear();

        $rows = $this->repo->findUnpublished(2);

        self::assertCount(2, $rows);
        $ids = array_map(static fn (TradeOutboxMessage $m): int => (int) $m->getId(), $rows);
        self::assertSame($ids, array_values($ids));
        sort($ids);
        self::assertSame($ids, array_values($ids));
    }

    public function testClaimReturnsTrueThenPreventsDoubleClaim(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();
        $this->em->clear();

        self::assertTrue($this->repo->claim($id, new \DateTimeImmutable('+1 minute')));
        // availableAt was advanced, so a second claim in the same window must fail
        self::assertFalse($this->repo->claim($id, new \DateTimeImmutable('+1 minute')));

        $unpublished = array_map(
            static fn (TradeOutboxMessage $m): int => (int) $m->getId(),
            $this->repo->findUnpublished(),
        );
        self::assertNotContains($id, $unpublished);
    }

    public function testClaimReturnsFalseWhenAlreadyPublished(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();
        $this->setPublishedAt($id);
        $this->em->clear();

        self::assertFalse($this->repo->claim($id, new \DateTimeImmutable('+1 minute')));
    }

    public function testClaimReturnsFalseWhenAvailableAtIsInTheFuture(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();
        $this->setAvailableAt($id, new \DateTimeImmutable('+10 minutes'));
        $this->em->clear();

        self::assertFalse($this->repo->claim($id, new \DateTimeImmutable('+1 minute')));
    }

    public function testClaimReturnsFalseForUnknownId(): void
    {
        self::assertFalse($this->repo->claim(999999, new \DateTimeImmutable('+1 minute')));
    }

    public function testDeferIncrementsAttemptsAndSetsErrorAndAvailableAt(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();

        $this->repo->defer($id, 'dispatch failed', new \DateTimeImmutable('+5 minutes'));
        $this->em->clear();

        $reloaded = $this->repo->find($id);
        self::assertInstanceOf(TradeOutboxMessage::class, $reloaded);
        self::assertSame(1, self::attemptsOf($reloaded));
        self::assertSame('dispatch failed', self::lastErrorOf($reloaded));
        self::assertGreaterThan(new \DateTimeImmutable(), self::availableAtOf($reloaded));

        $unpublished = array_map(
            static fn (TradeOutboxMessage $m): int => (int) $m->getId(),
            $this->repo->findUnpublished(),
        );
        self::assertNotContains($id, $unpublished);
    }

    public function testDeferAccumulatesAttemptsAcrossCalls(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();

        $this->repo->defer($id, 'first failure', new \DateTimeImmutable('+5 minutes'));
        $this->repo->defer($id, 'second failure', new \DateTimeImmutable('+5 minutes'));
        $this->em->clear();

        $reloaded = $this->repo->find($id);
        self::assertInstanceOf(TradeOutboxMessage::class, $reloaded);
        self::assertSame(2, self::attemptsOf($reloaded));
        self::assertSame('second failure', self::lastErrorOf($reloaded));
    }

    public function testDeferDoesNothingWhenMessageAlreadyPublished(): void
    {
        $message = $this->createMessage();
        $id = (int) $message->getId();
        $this->setPublishedAt($id);
        $this->em->clear();

        $this->repo->defer($id, 'too late', new \DateTimeImmutable('+5 minutes'));
        $this->em->clear();

        $reloaded = $this->repo->find($id);
        self::assertInstanceOf(TradeOutboxMessage::class, $reloaded);
        self::assertSame(0, self::attemptsOf($reloaded));
        self::assertNull(self::lastErrorOf($reloaded));
    }

    public function testPublishOutboxCommandRunsAgainstRealDatabase(): void
    {
        $created = $this->createMessage('trade.order.created.v1', 'order-real-1');
        $cancelled = $this->createMessage('trade.order.cancelled.v1', 'order-real-2');
        $unsupported = $this->createMessage('trade.unknown.topic.v1', 'order-real-3');
        $this->em->clear();

        $bus = $this->createMock(MessageBusInterface::class);
        $dispatched = [];
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return Envelope::wrap($msg);
            });

        $command = new PublishOutboxCommand($this->repo, $this->em, $bus);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 2 Trade outbox message(s).', $tester->getDisplay());
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(TradeOrderCreatedMessage::class, $dispatched[0]);
        self::assertInstanceOf(TradeOrderCancelledMessage::class, $dispatched[1]);
        self::assertSame('order-real-1', $dispatched[0]->envelope['aggregateId']);
        self::assertSame('order-real-2', $dispatched[1]->envelope['aggregateId']);

        $this->em->clear();

        $createdRow = $this->repo->find((int) $created->getId());
        $cancelledRow = $this->repo->find((int) $cancelled->getId());
        $unsupportedRow = $this->repo->find((int) $unsupported->getId());

        self::assertInstanceOf(TradeOutboxMessage::class, $createdRow);
        self::assertInstanceOf(TradeOutboxMessage::class, $cancelledRow);
        self::assertInstanceOf(TradeOutboxMessage::class, $unsupportedRow);

        self::assertNotNull($createdRow->getPublishedAt());
        self::assertNotNull($cancelledRow->getPublishedAt());
        self::assertNull($unsupportedRow->getPublishedAt());
        self::assertSame(1, self::attemptsOf($unsupportedRow));
        self::assertSame(
            'Unsupported Trade outbox topic: trade.unknown.topic.v1',
            self::lastErrorOf($unsupportedRow),
        );
        self::assertGreaterThan(new \DateTimeImmutable(), self::availableAtOf($unsupportedRow));
    }

    public function testPublishOutboxCommandDefersOnDispatchFailureAgainstRealDatabase(): void
    {
        $message = $this->createMessage('trade.order.created.v1', 'order-fail-1');
        $this->em->clear();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('real bus down'));

        $command = new PublishOutboxCommand($this->repo, $this->em, $bus);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Trade outbox message(s).', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->repo->find((int) $message->getId());
        self::assertInstanceOf(TradeOutboxMessage::class, $reloaded);
        self::assertNull($reloaded->getPublishedAt());
        self::assertSame(1, self::attemptsOf($reloaded));
        self::assertSame('real bus down', self::lastErrorOf($reloaded));
    }

    public function testUnsupportedTopicIsEventuallyQuarantined(): void
    {
        $this->markTestSkipped('See report — bug 1: no max-attempts cap / dead-letter for unsupported or failing topics.');
    }

    public function testSuccessfulPublishClearsPreviousFailureMetadata(): void
    {
        $this->markTestSkipped('See report — bug 2: successful publish does not reset attempts/lastError on the row.');
    }

    private static function attemptsOf(TradeOutboxMessage $message): int
    {
        return (int) (new \ReflectionProperty(TradeOutboxMessage::class, 'attempts'))->getValue($message);
    }

    private static function lastErrorOf(TradeOutboxMessage $message): ?string
    {
        return (new \ReflectionProperty(TradeOutboxMessage::class, 'lastError'))->getValue($message);
    }

    private static function availableAtOf(TradeOutboxMessage $message): \DateTimeImmutable
    {
        return (new \ReflectionProperty(TradeOutboxMessage::class, 'availableAt'))->getValue($message);
    }
}
