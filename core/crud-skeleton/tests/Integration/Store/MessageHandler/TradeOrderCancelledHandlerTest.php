<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store\MessageHandler;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Message\TradeOrderCancelledMessage;
use Doctrine\ORM\EntityManagerInterface;

final class TradeOrderCancelledHandlerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOutboxMessage message')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreConsumedEvent event')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreTradeOrderCancellation cancellation')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOrder storeOrder')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    public function testRejectsEnvelopeWithWrongType(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.cancelled.v1 envelope.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000A1',
            'type' => 'trade.order.created',
            'version' => 1,
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000A2', 'storeUuid' => '00000000-0000-4000-8000-0000000000A3', 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testRejectsEnvelopeWithWrongVersion(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.cancelled.v1 envelope.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000A4',
            'type' => 'trade.order.cancelled',
            'version' => 2,
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000A5', 'storeUuid' => '00000000-0000-4000-8000-0000000000A6', 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testRejectsEnvelopeWithNonStringEventId(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.cancelled.v1 envelope.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => 123,
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000A7', 'storeUuid' => '00000000-0000-4000-8000-0000000000A8', 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testRejectsEnvelopeWithNonArrayPayload(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.cancelled.v1 envelope.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000A9',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => 'not-an-array',
        ]));
    }

    public function testRejectsEnvelopeWithMissingPayloadFields(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.cancelled.v1 envelope.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000B1',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000B2', 'storeUuid' => '00000000-0000-4000-8000-0000000000B3'],
        ]));
    }

    public function testDuplicateEventIdIsIgnored(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $container->get(StoreServiceInterface::class)->createStore('cancel-dupe', 'Cancel Dupe', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-0000000000B4', 'cancel-dupe', 'Cancel Dupe', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $em->persist($order);
        $em->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);
        $message = new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000B5',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]);

        $handler($message);
        $handler($message);

        self::assertSame(StoreOrder::STATUS_CANCELLED, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertNotNull($container->get(StoreConsumedEventRepository::class)->findOneByEventId('00000000-0000-4000-8000-0000000000B5'));
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testTombstoneForUnknownOrderIsIdempotent(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('cancel-tomb', 'Cancel Tomb', 'UTC');
        $orderUuid = '00000000-0000-4000-8000-0000000000B6';
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000B7',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $orderUuid, 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000B8',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $orderUuid, 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $tombstones = $container->get(StoreTradeOrderCancellationRepository::class)->findBy(['tradeOrderUuid' => $orderUuid]);
        self::assertCount(1, $tombstones);
        self::assertNull($container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid));
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testConflictingTombstoneThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $storeA = $container->get(StoreServiceInterface::class)->createStore('cancel-conf-a', 'Cancel Conf A', 'UTC');
        $storeB = $container->get(StoreServiceInterface::class)->createStore('cancel-conf-b', 'Cancel Conf B', 'UTC');
        $orderUuid = '00000000-0000-4000-8000-0000000000B9';
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000C1',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $orderUuid, 'storeUuid' => $storeA->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Trade order cancellation conflicts with the Store order snapshot.');
        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000C2',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $orderUuid, 'storeUuid' => $storeB->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testCancelsPendingOrderWithoutReservation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $container->get(StoreServiceInterface::class)->createStore('cancel-pending', 'Cancel Pending', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-0000000000C3', 'cancel-pending', 'Cancel Pending', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $em->persist($order);
        $em->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000C4',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $stored = $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid());
        self::assertSame(StoreOrder::STATUS_CANCELLED, $stored?->getOperationalStatus());
        self::assertNull($stored?->getReservationId());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testCancelsAwaitingInventoryOrderAndRequestsRelease(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $container->get(StoreServiceInterface::class)->createStore('cancel-await', 'Cancel Await', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-0000000000C5', 'cancel-await', 'Cancel Await', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $order->awaitInventory('00000000-0000-4000-8000-0000000000C6');
        $em->persist($order);
        $em->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $handler(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000C7',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $stored = $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid());
        self::assertSame(StoreOrder::STATUS_CANCELLED, $stored?->getOperationalStatus());
        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        self::assertSame('inventory.reservation.release.requested.v1', $outbox[0]->getTopic());
        self::assertSame('inventory_reservation', $outbox[0]->getAggregateType());
        self::assertSame('00000000-0000-4000-8000-0000000000C6', $outbox[0]->getAggregateId());
        $payload = $outbox[0]->getPayload();
        self::assertSame('00000000-0000-4000-8000-0000000000C6', $payload['reservationId']);
        self::assertSame($store->getUuid(), $payload['storeUuid']);
        self::assertSame($order->getTradeOrderUuid(), $payload['tradeOrderUuid']);
        self::assertSame($order->getUuid(), $payload['storeOrderUuid']);
        self::assertSame('trade_order_cancelled', $payload['reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['requestedAt']);
    }
}
