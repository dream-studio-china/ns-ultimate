<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use PHPUnit\Framework\TestCase;

final class TradeOrderPrintAdapterTest extends TestCase
{
    public function testPaidEventQueuesStoreSpecificRouteAndDuplicateIsIgnored(): void
    {
        $store = new Store('shop-a', 'Shop A');
        $store->setSettings(['printing' => [
            'order' => [
                'enabled' => true,
                'deviceUuid' => 'device-a',
                'templateUuid' => 'template-a',
            ],
        ]]);
        $order = new Order();
        $order->setStatus(Order::STATUS_PAID);
        $lifecycle = new OrderStoreLifecycle($order->getUuid(), $store->getUuid());

        $lifecycleRepository = $this->createStub(EntityRepository::class);
        $lifecycleRepository->method('findOneBy')->willReturn($lifecycle);
        $storeRepository = $this->createStub(EntityRepository::class);
        $storeRepository->method('findOneBy')->willReturn($store);
        $requestRepository = $this->createStub(EntityRepository::class);
        /** @var array<string, PrinterOrderRequest> $knownRequests */
        $knownRequests = [];
        $requestRepository->method('findOneBy')->willReturnCallback(static function () use (&$knownRequests): ?PrinterOrderRequest {
            return $knownRequests === [] ? null : reset($knownRequests);
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            OrderStoreLifecycle::class => $lifecycleRepository,
            Store::class => $storeRepository,
            PrinterOrderRequest::class => $requestRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (object $request) use (&$knownRequests): bool {
            if (!$request instanceof PrinterOrderRequest) {
                return false;
            }
            $knownRequests[$request->getRequestKey()] = $request;
            return true;
        }));

        $adapter = new TradeOrderPrintAdapter($entityManager);
        $adapter->enqueueForPaidOrder($order);
        $adapter->enqueueForPaidOrder($order);

        self::assertCount(1, $knownRequests);
        $request = array_values($knownRequests)[0];
        self::assertSame($order->getUuid(), $request->getOrderUuid());
        self::assertSame($store->getUuid(), $request->getStoreUuid());
        self::assertSame('order', $request->getCaseKey());
        self::assertSame('paid', $request->getTrigger());
        self::assertSame('order', $request->getConfiguration()['scene']);
        self::assertSame([], $request->getPrintData()['order']['items']);
    }

    public function testSettlementRouteOnlyQueuesOnPaidTrigger(): void
    {
        $store = new Store('shop-a', 'Shop A');
        $store->setSettings(['printing' => [
            'settlement' => [
                'enabled' => true,
                'deviceUuid' => 'device-a',
                'templateUuid' => 'template-a',
            ],
        ]]);
        $order = new Order();
        $order->setStatus(Order::STATUS_PAID);
        $lifecycle = new OrderStoreLifecycle($order->getUuid(), $store->getUuid());
        $lifecycleRepository = $this->createStub(EntityRepository::class);
        $lifecycleRepository->method('findOneBy')->willReturn($lifecycle);
        $storeRepository = $this->createStub(EntityRepository::class);
        $storeRepository->method('findOneBy')->willReturn($store);
        $requestRepository = $this->createStub(EntityRepository::class);
        $requestRepository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            OrderStoreLifecycle::class => $lifecycleRepository,
            Store::class => $storeRepository,
            PrinterOrderRequest::class => $requestRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(PrinterOrderRequest::class));

        (new TradeOrderPrintAdapter($entityManager))->enqueueForPaidOrder($order);
    }

    public function testProductionCaseUsesAcceptedEventWhenStoreRequiresAcceptance(): void
    {
        $store = new Store('shop-a', 'Shop A');
        $store->setSettings([
            'order' => ['requireAcceptance' => true],
            'printing' => [
                'fullList' => [
                    'enabled' => true,
                    'deviceUuid' => 'device-a',
                    'templateUuid' => 'template-a',
                ],
            ],
        ]);
        $order = new Order();
        $lifecycle = new OrderStoreLifecycle($order->getUuid(), $store->getUuid());
        $lifecycleRepository = $this->createStub(EntityRepository::class);
        $lifecycleRepository->method('findOneBy')->willReturn($lifecycle);
        $orderRepository = $this->createStub(EntityRepository::class);
        $orderRepository->method('findOneBy')->willReturn($order);
        $storeRepository = $this->createStub(EntityRepository::class);
        $storeRepository->method('findOneBy')->willReturn($store);
        $requestRepository = $this->createStub(EntityRepository::class);
        $requestRepository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            OrderStoreLifecycle::class => $lifecycleRepository,
            Order::class => $orderRepository,
            Store::class => $storeRepository,
            PrinterOrderRequest::class => $requestRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $queuedRequest = null;
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (object $request) use (&$queuedRequest): bool {
            $queuedRequest = $request;
            return $request instanceof PrinterOrderRequest;
        }));
        $entityManager->expects(self::once())->method('flush');

        (new TradeOrderPrintAdapter($entityManager))->enqueueForAcceptedOrder($order->getUuid(), $store->getUuid());

        self::assertInstanceOf(PrinterOrderRequest::class, $queuedRequest);
        self::assertSame('accepted', $queuedRequest->getTrigger());
        self::assertSame('fullList', $queuedRequest->getCaseKey());
    }
}
