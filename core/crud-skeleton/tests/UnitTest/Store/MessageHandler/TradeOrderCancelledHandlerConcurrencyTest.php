<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\MessageHandler;

use App\Store\Entity\StoreConsumedEvent;
use App\Store\MessageHandler\TradeOrderCancelledHandler;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOutboxService;
use App\Trade\Message\TradeOrderCancelledMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class TradeOrderCancelledHandlerConcurrencyTest extends TestCase
{
    public function testReturnsWhenEventIsConsumedInsideTheTransaction(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );

        $consumedEvent = new StoreConsumedEvent('00000000-0000-4000-8000-0000000000D1', 'trade.order.cancelled.v1', '00000000-0000-4000-8000-0000000000D2', 'payload-hash');
        $consumedRepository = $this->createMock(StoreConsumedEventRepository::class);
        $calls = 0;
        $consumedRepository->method('findOneBy')->willReturnCallback(
            static function () use (&$calls, $consumedEvent): ?StoreConsumedEvent {
                return ++$calls > 1 ? $consumedEvent : null;
            },
        );

        $storeOrderRepository = $this->createMock(StoreOrderRepository::class);
        $storeOrderRepository->method('findOneByTradeOrderUuid')->willReturn(null);
        $cancellationRepository = new StoreTradeOrderCancellationRepository($this->createMock(ManagerRegistry::class));

        $entityManager->expects(self::never())->method('persist');

        $handler = new TradeOrderCancelledHandler(
            $consumedRepository,
            $storeOrderRepository,
            $cancellationRepository,
            new StoreOutboxService($entityManager),
            $entityManager,
        );
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000D1',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000D2', 'storeUuid' => '00000000-0000-4000-8000-0000000000D3', 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertSame(2, $calls);
    }
}
