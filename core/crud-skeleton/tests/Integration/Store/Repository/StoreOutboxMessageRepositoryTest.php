<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store\Repository;

use App\Store\Entity\StoreOutboxMessage;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StoreOutboxMessageRepositoryTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $entityManager;
    private StoreOutboxMessageRepository $repository;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(StoreOutboxMessageRepository::class);
        $this->entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOutboxMessage message')->execute();
    }

    public function testFindUnpublishedReturnsOnlyAvailableUnpublishedMessagesOrderedById(): void
    {
        $available = $this->persistMessage('store.order.accepted.v1', 'available');
        $future = $this->persistMessage('store.order.rejected.v1', 'future', unavailable: true);
        $published = $this->persistMessage('inventory.reservation.requested.v1', 'published', published: true);

        $unpublished = $this->repository->findUnpublished();

        self::assertCount(1, $unpublished);
        self::assertSame($available->getId(), $unpublished[0]->getId());
        self::assertNotNull($available->getId());
        self::assertNotNull($future->getId());
        self::assertNotNull($published->getId());
    }

    public function testFindUnpublishedRespectsLimitAndOrdersByAscendingId(): void
    {
        $a = $this->persistMessage('store.order.accepted.v1', 'limit-a');
        $b = $this->persistMessage('store.order.accepted.v1', 'limit-b');
        $c = $this->persistMessage('store.order.accepted.v1', 'limit-c');

        $ids = array_map(static fn (StoreOutboxMessage $message): ?int => $message->getId(), $this->repository->findUnpublished(2));

        self::assertCount(2, $ids);
        self::assertSame([$a->getId(), $b->getId()], $ids);
        self::assertNotContains($c->getId(), $ids);
    }

    public function testClaimReturnsTrueAndMovesAvailabilityIntoTheFuture(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'claimable');

        self::assertTrue($this->repository->claim((int) $message->getId(), new \DateTimeImmutable('+1 minute')));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(StoreOutboxMessage::class, $message->getId());
        self::assertNotNull($reloaded);
        self::assertGreaterThan(new \DateTimeImmutable(), $reloaded->getAvailableAt());
        self::assertNull($reloaded->getPublishedAt());

        self::assertSame([], $this->repository->findUnpublished());
    }

    public function testClaimReturnsFalseForAnAlreadyClaimedMessage(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'double-claim');

        self::assertTrue($this->repository->claim((int) $message->getId(), new \DateTimeImmutable('+1 minute')));
        self::assertFalse($this->repository->claim((int) $message->getId(), new \DateTimeImmutable('+1 minute')));
    }

    public function testClaimReturnsFalseForAPublishedMessage(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'already-published', published: true);

        self::assertFalse($this->repository->claim((int) $message->getId(), new \DateTimeImmutable('+1 minute')));
    }

    public function testClaimReturnsFalseForAMessageNotYetAvailable(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'not-yet', unavailable: true);

        self::assertFalse($this->repository->claim((int) $message->getId(), new \DateTimeImmutable('+1 minute')));
    }

    public function testClaimReturnsFalseForAMissingMessage(): void
    {
        self::assertFalse($this->repository->claim(999999999, new \DateTimeImmutable('+1 minute')));
    }

    public function testDeferIncrementsAttemptsAndRecordsErrorAndRetryTime(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'deferred');
        $retryAt = new \DateTimeImmutable('+5 minutes');

        $this->repository->defer((int) $message->getId(), 'temporary failure', $retryAt);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(StoreOutboxMessage::class, $message->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getAttempts());
        self::assertSame('temporary failure', $reloaded->getLastError());
        self::assertGreaterThan(new \DateTimeImmutable(), $reloaded->getAvailableAt());

        self::assertSame([], $this->repository->findUnpublished());
    }

    public function testDeferAccumulatesAttemptsAcrossRetries(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'retry-twice');

        $this->repository->defer((int) $message->getId(), 'first', new \DateTimeImmutable('+1 minute'));
        $this->repository->defer((int) $message->getId(), 'second', new \DateTimeImmutable('+2 minutes'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(StoreOutboxMessage::class, $message->getId());
        self::assertSame(2, $reloaded?->getAttempts());
        self::assertSame('second', $reloaded?->getLastError());
    }

    public function testDeferLeavesAPublishedMessageUntouched(): void
    {
        $message = $this->persistMessage('store.order.accepted.v1', 'published-defer', published: true);

        $this->repository->defer((int) $message->getId(), 'should-not-write', new \DateTimeImmutable('+5 minutes'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(StoreOutboxMessage::class, $message->getId());
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getAttempts());
        self::assertNull($reloaded->getLastError());
        self::assertNotNull($reloaded->getPublishedAt());
    }

    public function testFindUnpublishedAfterManualRecordAttemptOnlyReturnsReavailableMessages(): void
    {
        $this->persistMessage('store.order.accepted.v1', 'available-again', unavailable: false);
        $this->persistMessage('store.order.rejected.v1', 'still-waiting', unavailable: true);

        $this->entityManager->clear();

        $unpublished = $this->repository->findUnpublished();
        self::assertCount(1, $unpublished);
        self::assertSame('available-again', $unpublished[0]->getAggregateId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistMessage(
        string $topic,
        string $aggregateId,
        bool $unavailable = false,
        bool $published = false,
        array $payload = ['orderUuid' => 'order-11111111-1111-4111-8111-111111111111'],
    ): StoreOutboxMessage {
        $message = new StoreOutboxMessage($topic, 'store_order', $aggregateId, $payload);
        if ($unavailable) {
            $message->recordAttempt(null, new \DateTimeImmutable('+1 hour'));
        }
        if ($published) {
            $message->markPublished();
        }
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }
}
