<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderAcceptedMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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

    public function testSnapshotBuilderRetainsSingleAndKitchenFilteredBehavior(): void
    {
        $adapter = new TradeOrderPrintAdapter($this->createStub(EntityManagerInterface::class));
        $order = new Order();
        $store = new Store('shop-a', 'Shop A');
        $snapshots = $adapter->buildPrintData($order, $store);
        self::assertCount(1, $snapshots);
        self::assertSame($order->getUuid(), $snapshots[0]['order']['uuid']);
        self::assertSame([], $snapshots[0]['order']['items']);
        self::assertSame([], $adapter->buildPrintData($order, $store, false, true));
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
