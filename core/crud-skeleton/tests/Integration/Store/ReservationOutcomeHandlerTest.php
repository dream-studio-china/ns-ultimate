<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Inventory\Message\ReservationConfirmedMessage;
use App\Inventory\Message\ReservationRejectedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ReservationOutcomeHandlerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Store\\Entity\\StoreOutboxMessage message')->execute();
        $em->createQuery('DELETE FROM App\\Store\\Entity\\StoreConsumedEvent event')->execute();
        $em->createQuery('DELETE FROM App\\Store\\Entity\\StoreOrder storeOrder')->execute();
        $em->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    public function testConfirmationAcceptsMatchingAwaitingStoreOrder(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);
        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-000000000020', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00'])]));
        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-000000000020', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00'])]));
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
    }

    public function testRejectionRejectsMatchingAwaitingStoreOrder(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);
        $handler(new ReservationRejectedMessage(['eventId' => '00000000-0000-4000-8000-000000000021', 'type' => 'inventory.reservation.rejected', 'version' => 1, 'payload' => $this->outcomePayload($order, ['reasonCode' => 'OUT_OF_STOCK', 'reason' => 'No stock.', 'rejectedAt' => '2026-07-26T00:00:00+00:00'])]));
        $stored = $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid());
        self::assertSame(StoreOrder::STATUS_REJECTED, $stored?->getOperationalStatus());
        self::assertSame('OUT_OF_STOCK', $stored?->getRejectionCode());
    }

    public function testRejectionPreservesInventoryReasonCode(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);
        $handler(new ReservationRejectedMessage(['eventId' => '00000000-0000-4000-8000-000000000024', 'type' => 'inventory.reservation.rejected', 'version' => 1, 'payload' => $this->outcomePayload($order, ['reasonCode' => 'SPECIFICATION_NOT_STOCKABLE', 'reason' => 'Not stockable.', 'rejectedAt' => '2026-07-26T00:00:00+00:00'])]));

        self::assertSame('SPECIFICATION_NOT_STOCKABLE', $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getRejectionCode());
    }

    public function testReleaseAcknowledgementIsConsumedWithoutChangingTheCancelledOrder(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $order->cancel();
        $container->get(EntityManagerInterface::class)->flush();
        $handler = $container->get(\App\Store\MessageHandler\ReservationReleasedHandler::class);
        $message = new ReservationReleasedMessage(['eventId' => '00000000-0000-4000-8000-000000000025', 'type' => 'inventory.reservation.released', 'version' => 1, 'payload' => ['reservationId' => $order->getReservationId(), 'releasedAt' => '2026-07-26T00:00:00+00:00']]);
        $handler($message);
        $handler($message);

        self::assertSame(StoreOrder::STATUS_CANCELLED, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertNotNull($container->get(StoreConsumedEventRepository::class)->findOneByEventId('00000000-0000-4000-8000-000000000025'));
    }

    /** @return array{\Symfony\Component\DependencyInjection\ContainerInterface, StoreOrder} */
    private function awaitingOrder(): array
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = new Store('outcome', 'Outcome Store', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-000000000022', 'outcome', 'Outcome Store', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $order->awaitInventory('00000000-0000-4000-8000-000000000023');
        $em->persist($store);
        $em->persist($order);
        $em->flush();

        return [$container, $order];
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function outcomePayload(StoreOrder $order, array $extra): array
    {
        return array_merge([
            'reservationId' => $order->getReservationId(),
            'storeUuid' => $order->getStore()->getUuid(),
            'tradeOrderUuid' => $order->getTradeOrderUuid(),
            'storeOrderUuid' => $order->getUuid(),
        ], $extra);
    }
}
