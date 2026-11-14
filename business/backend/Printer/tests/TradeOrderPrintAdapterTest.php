<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Store\Entity\Product;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderAcceptedMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Printer\Adapter\OrderPrintRuleEventAdapter;
use NsUltimate\Business\Printer\Adapter\StoreOrderAcceptedPrintHandler;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintEventSubscriber;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Service\OrderPrintRequestWriter;
use NsUltimate\Business\Printer\Service\OrderPrintRuleEnqueuer;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
use NsUltimate\Business\Printer\Service\OrderPrintRuleSelector;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;

final class TradeOrderPrintAdapterTest extends TestCase
{
    public function testWorkflowEventsResolveRulesOnlyForCompletedOrderStates(): void
    {
        $order = new Order();
        $store = new Store('shop-a', 'Shop A');
        $em = $this->entityManager($order, $store, 2);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $subscriber = new TradeOrderPrintEventSubscriber($this->adapter($em));
        self::assertSame([
            'workflow.order.completed.confirm' => 'onConfirmationCompleted',
            'workflow.order.completed.pay' => 'onPaymentCompleted',
        ], $subscriber::getSubscribedEvents());

        $event = new CompletedEvent($order, new Marking());
        $subscriber->onConfirmationCompleted($event);
        $subscriber->onPaymentCompleted($event);
        $order->setStatus(Order::STATUS_CONFIRMED);
        $subscriber->onConfirmationCompleted($event);
        $order->setStatus(Order::STATUS_PAID);
        $subscriber->onPaymentCompleted($event);
        $subscriber->onConfirmationCompleted(new CompletedEvent(new \stdClass(), new Marking()));
    }

    public function testAcceptedHandlerChecksStoreAssociationAndUsesTransaction(): void
    {
        $order = new Order();
        $store = new Store('shop-a', 'Shop A');
        $em = $this->entityManager($order, $store, 1);
        $em->expects(self::exactly(2))->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $handler = new StoreOrderAcceptedPrintHandler($this->adapter($em));
        $handler(new StoreOrderAcceptedMessage(['payload' => ['orderUuid' => $order->getUuid(), 'storeUuid' => 'wrong']]));
        $handler(new StoreOrderAcceptedMessage(['payload' => ['orderUuid' => $order->getUuid(), 'storeUuid' => $store->getUuid()]]));
    }

    public function testAcceptedHandlerRejectsInvalidEnvelope(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $handler = new StoreOrderAcceptedPrintHandler($this->adapter($em));
        $this->expectException(\InvalidArgumentException::class);
        $handler(new StoreOrderAcceptedMessage(['payload' => ['orderUuid' => 1]]));
    }

    public function testSnapshotBuilderRetainsSingleAndFilteredBehavior(): void
    {
        $order = new Order();
        $store = new Store('shop-a', 'Shop A');
        $adapter = $this->snapshotAdapter($order, $store, []);
        $snapshots = $adapter->buildPrintData($order, $store);
        self::assertCount(1, $snapshots);
        self::assertSame($order->getUuid(), $snapshots[0]['order']['uuid']);
        self::assertSame([], $snapshots[0]['order']['items']);
        self::assertNull($snapshots[0]['order']['id']);
        self::assertSame('', $snapshots[0]['order']['counterName']);
        self::assertSame('', $snapshots[0]['order']['counterLabel']);
        self::assertSame('', $snapshots[0]['order']['labelHeader']);
        self::assertSame('¥0.00', $snapshots[0]['order']['totalPrice']);
        self::assertSame('¥0.00', $snapshots[0]['order']['discount']);
        self::assertSame('¥0.00', $snapshots[0]['order']['amountDue']);
        self::assertSame('', $snapshots[0]['order']['paymentUrl']);
        self::assertSame([], $adapter->buildPrintData($order, $store, false, 'entity.getQuantity() > 0'));
    }

    public function testSnapshotUsesBoundCounterNamesAndAddsItemAliasesWithoutChangingMoneyTypes(): void
    {
        $order = (new Order())->setTotalAmount(3000)->addItem((new OrderItem())
            ->setProductSnapshot(['name' => 'Jasmine tea'])
            ->setSpecSnapshot(['name' => 'Large'])
            ->setQuantity(2)->setUnitPrice(1500)->setPrice(3000));
        $store = new Store('shop-a', 'Shop A');
        $counter = (new Counter())->setCode('A01')->setName('Table A');
        (new \ReflectionProperty($counter, 'id'))->setValue($counter, 9);
        $binding = (new OrderCounter())->setCounter($counter);
        $counter->setName('Renamed table')->setCode('NEW');
        $adapter = $this->snapshotAdapter($order, $store, [$binding]);
        $data = $adapter->buildPrintData($order, $store)[0]['order'];

        self::assertSame('Table A', $data['counterName']);
        self::assertSame('Table A(#9)', $data['counterLabel']);
        self::assertSame('Table A', $data['labelHeader']);
        self::assertSame($order->getCreatedAt()->format('Y/m/d H:i:s'), $data['createdTime']);
        self::assertSame(3000, $data['totalAmount']);
        self::assertSame('¥30.00', $data['totalPrice']);
        self::assertSame('¥0.00', $data['discount']);
        self::assertSame('¥30.00', $data['amountDue']);
        $item = $data['items'][0];
        self::assertSame('Jasmine tea', $item['product']);
        self::assertSame('Large', $item['spec']);
        self::assertSame('Large', $item['subTitle']);
        self::assertSame('x2', $item['quantityLabel']);
        self::assertSame(1500, $item['unitPrice']);
        self::assertSame(3000, $item['price']);
        self::assertSame($item['amount'], $item['price']);

        $tickets = $adapter->buildPrintData($order, $store, true);
        self::assertCount(2, $tickets);
        foreach ($tickets as $index => $ticket) {
            self::assertSame($data, $ticket['order']);
            self::assertSame($item['product'], $ticket['item']['product']);
            self::assertSame($item['subTitle'], $ticket['item']['subTitle']);
            self::assertSame($item['price'], $ticket['item']['price']);
            self::assertSame($index + 1, $ticket['item']['index']);
        }
    }

    public function testSnapshotFormatsTotalsAndBuildsPaymentUrlFromSnapshotIdentities(): void
    {
        $order = (new Order())->setTotalAmount(4300)->addItem((new OrderItem())
            ->setSpecSnapshot(['name' => 'Large'])->setQuantity(2)->setUnitPrice(1500)->setPrice(3000))
            ->addItem((new OrderItem())
                ->setSpecSnapshot(['name' => 'Slice'])->setQuantity(1)->setUnitPrice(1800)->setPrice(1800));
        $store = new Store('shop-a', 'Shop A');
        $adapter = $this->snapshotAdapter($order, $store, [], 'https://shop.example/');
        $data = $adapter->buildPrintData($order, $store)[0]['order'];

        self::assertSame('¥48.00', $data['totalPrice']);
        self::assertSame('¥5.00', $data['discount']);
        self::assertSame('¥43.00', $data['amountDue']);
        self::assertSame(
            'https://shop.example/h5/payment/index.html?orderUuid='.$order->getUuid().'&storeUuid='.$store->getUuid(),
            $data['paymentUrl'],
        );
        self::assertSame('Large', $data['items'][0]['subTitle']);
    }

    public function testSnapshotIgnoresInvalidPaymentBaseAndUntrustedDemoMetadata(): void
    {
        $order = (new Order())->setMetadata(['staffName' => 'Untrusted', 'paymentUrl' => 'https://evil.example']);
        $store = new Store('shop-a', 'Shop A');
        foreach (['', 'ftp://shop.example', 'not-a-url'] as $base) {
            $data = $this->snapshotAdapter($order, $store, [], $base)->buildPrintData($order, $store)[0]['order'];
            self::assertSame('', $data['paymentUrl'], $base);
        }
        $data = $this->snapshotAdapter($order, $store, [])->buildPrintData($order, $store)[0]['order'];
        foreach (['staffName', 'bookingName', 'waiterName', 'isAdd', 'laterPay'] as $field) {
            self::assertArrayNotHasKey($field, $data);
        }
        self::assertSame('¥0.00', $data['totalPrice']);
    }

    public function testMultipleCountersUseStableBindingOrderAndCodeFallback(): void
    {
        $order = new Order();
        $store = new Store('shop-a', 'Shop A');
        $bindings = [
            (new OrderCounter())->setCounter((new Counter())->setName('Table A')->setCode('A01')),
            (new OrderCounter())->setCounter((new Counter())->setCode('B01')),
        ];
        $data = $this->snapshotAdapter($order, $store, $bindings)->buildPrintData($order, $store)[0]['order'];
        self::assertSame('Table A, B01', $data['counterName']);
        self::assertSame('Table A, B01', $data['counterLabel']);
        self::assertSame('Table A, B01', $data['labelHeader']);
    }

    public function testSnapshotPrefersProductMetadataSubtitleAndFallsBackToSpecification(): void
    {
        $product = (new Product())->setName('Jasmine tea')->setMetadata(['subtitle' => 'Less ice']);
        $order = (new Order())->addItem((new OrderItem())
            ->setProductSnapshot(['uuid' => $product->getUuid(), 'name' => 'Jasmine tea'])
            ->setSpecSnapshot(['name' => 'Large'])->setQuantity(1)->setUnitPrice(1500)->setPrice(1500))
            ->addItem((new OrderItem())
                ->setProductSnapshot(['uuid' => 'missing-product', 'name' => 'Cake'])
                ->setSpecSnapshot(['name' => 'Slice'])->setQuantity(1)->setUnitPrice(1800)->setPrice(1800));
        $store = new Store('shop-a', 'Shop A');
        $data = $this->snapshotAdapter($order, $store, [], '', $product)->buildPrintData($order, $store)[0]['order'];

        self::assertSame('Less ice', $data['items'][0]['subTitle']);
        self::assertSame('Slice', $data['items'][1]['subTitle']);
    }

    /** @param list<OrderCounter> $bindings */
    private function snapshotAdapter(Order $order, Store $store, array $bindings, string $paymentBaseUrl = '', ?Product $product = null): TradeOrderPrintAdapter
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria, ?array $orderBy = null) use ($order, $store, $bindings): array {
                self::assertSame(['tradeOrderUuid' => $order->getUuid(), 'storeUuid' => $store->getUuid()], $criteria);
                self::assertSame(['id' => 'ASC'], $orderBy);
                return $bindings;
            },
        );
        $products = $this->createStub(EntityRepository::class);
        $products->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Product => $product !== null && ($criteria['uuid'] ?? null) === $product->getUuid() ? $product : null,
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            OrderCounter::class => $repository,
            Product::class => $products,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        return new TradeOrderPrintAdapter($em, null, $paymentBaseUrl);
    }

    private function entityManager(Order $order, Store $store, int $selections): EntityManagerInterface
    {
        $lifecycle = $this->createStub(EntityRepository::class);
        $lifecycle->method('findOneBy')->willReturn(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));
        $orders = $this->createStub(EntityRepository::class);
        $orders->method('findOneBy')->willReturn($order);
        $stores = $this->createStub(EntityRepository::class);
        $stores->method('findOneBy')->willReturn($store);
        $rules = $this->createMock(EntityRepository::class);
        $rules->expects(self::exactly($selections))->method('findBy')
            ->with(['storeUuid' => $store->getUuid(), 'enabled' => true], ['id' => 'ASC'])->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::atLeastOnce())->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            OrderStoreLifecycle::class => $lifecycle,
            Order::class => $orders,
            Store::class => $stores,
            PrinterOrderRule::class => $rules,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        return $em;
    }

    private function adapter(EntityManagerInterface $em): OrderPrintRuleEventAdapter
    {
        $matcher = new OrderPrintRuleMatcher();
        return new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($this->createStub(Connection::class)),
            new TradeOrderPrintAdapter($em), $em,
        ));
    }
}
