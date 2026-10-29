<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\StoreContextResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use NsUltimate\Business\Counter\Adapter\CounterTradeOrderService;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Counter\Service\CounterOrderMergeService;
use NsUltimate\Business\Counter\Service\CounterSessionService;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CounterOrderMergeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StoreContext $context;
    private Counter $counter;
    private User $user;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $store = new Store('counter-merge-test', 'Counter merge test');
        $this->context = new StoreContext($store->getUuid(), $store->getCode(), $store->getName());
        $this->counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Table A01');
        $this->user = (new User())->setEmail('counter-merge@example.test')->setUsername('counter-merge');
        foreach ([$store, $this->counter, $this->user] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testOrderServiceDecorationIsComposed(): void
    {
        self::assertInstanceOf(CounterTradeOrderService::class, self::getContainer()->get(OrderServiceInterface::class));
    }

    public function testMergeMarksSourcesAndCreatesOneBoundTarget(): void
    {
        $sourceA = $this->source();
        $sourceB = $this->source();
        $target = $this->merger()->merge($this->counter, $this->user);

        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
        self::assertSame([], $this->em->getRepository(TradeOutboxMessage::class)->findBy(['aggregateId' => $target->getUuid()]));
        self::assertSame(200, $target->getTotalAmount());
        self::assertSame($this->user, $target->getUser());
        foreach ([$sourceA, $sourceB] as $source) {
            self::assertSame('merged', $source->getStatus());
            self::assertSame($target->getUuid(), $source->getMetadata()['mergedIntoOrderUuid']);
            self::assertTrue($source->getMetadata()['counterMergeInventoryReconciliationRequired']);
        }
        self::assertCount(2, $this->em->getRepository(OrderMerge::class)->findAll());
        self::assertFalse(self::getContainer()->get('state_machine.order')->can($target, 'cancel'));
        $binding = $this->em->getRepository(OrderCounter::class)->findOneBy(['tradeOrderUuid' => $target->getUuid()]);
        self::assertNotNull($binding?->getCounterSession());
        self::assertSame(
            $this->em->getRepository(OrderCounter::class)->findOneBy(['tradeOrderUuid' => $sourceA->getUuid()])->getCounterSession(),
            $binding->getCounterSession(),
        );
        $this->em->clear();
        self::assertSame('merged', $this->em->getRepository(Order::class)->findOneBy(['uuid' => $sourceA->getUuid()])->getStatus());
    }

    public function testMergeRepricesUsingTheRealStoreCatalog(): void
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
        $product = (new Product($store))->setName('Coffee');
        $specification = (new Specification())->setProduct($product)->setName('Large')->setPrice(250);
        $this->em->persist($product);
        $this->em->persist($specification);
        $this->em->flush();
        $sourceA = $this->source();
        $sourceB = $this->source();
        foreach ([$sourceA, $sourceB] as $source) {
            $source->getItems()->first()->setSpecificationUuid($specification->getUuid());
        }
        $this->em->flush();

        $target = $this->merger(self::getContainer()->get(OrderServiceInterface::class))->merge($this->counter, $this->user);
        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
        self::assertSame(500, $target->getTotalAmount());
        self::assertSame([250, 250], array_map(static fn (OrderItem $item): int => $item->getUnitPrice(), $target->getItems()->toArray()));
        self::assertSame('Coffee', $target->getItems()->first()->getProductSnapshot()['name']);
    }

    public function testUnavailableSpecificationRollsBackRealPricingMerge(): void
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
        $product = (new Product($store))->setName('Coffee');
        $specification = (new Specification())->setProduct($product)->setName('Large')->setPrice(250)->setStatus(Specification::STATUS_INACTIVE);
        $this->em->persist($product);
        $this->em->persist($specification);
        $this->em->flush();
        $sourceA = $this->source();
        $sourceB = $this->source();
        foreach ([$sourceA, $sourceB] as $source) {
            $source->getItems()->first()->setSpecificationUuid($specification->getUuid());
        }
        $this->em->flush();
        try {
            $this->merger(self::getContainer()->get(OrderServiceInterface::class))->merge($this->counter, $this->user);
            self::fail('Inactive specifications must not be repriced.');
        } catch (\App\Trade\Exception\SpecificationNotFoundException) {
            $this->em->clear();
            self::assertCount(2, $this->em->getRepository(Order::class)->findAll());
            self::assertSame([], $this->em->getRepository(OrderMerge::class)->findAll());
            self::assertSame(Order::STATUS_CONFIRMED, $this->em->getRepository(Order::class)->findOneBy(['uuid' => $sourceA->getUuid()])->getStatus());
        }
    }

    public function testAuthenticatedMergeEndpointUsesCurrentUserAndReturnsTarget(): void
    {
        $source = $this->source();
        $this->source();
        self::getContainer()->set(CounterOrderMergeService::class, $this->merger());
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_STORE_CODE' => $this->context->storeCode];
        $path = '/api/v1/app/counters/'.$this->counter->getUuid().'/orders';
        $response = self::$kernel->handle(Request::create($path.'/merge', 'POST', [], [], [], $headers));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(Order::STATUS_CONFIRMED, $payload['data']['status']);
        $targetUuid = $payload['data']['uuid'];
        $listing = self::$kernel->handle(Request::create($path, 'GET', [], [], [], $headers));
        self::assertSame(200, $listing->getStatusCode(), (string) $listing->getContent());
        $items = json_decode((string) $listing->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $byUuid = array_column($items, null, 'uuid');
        self::assertSame($targetUuid, $byUuid[$source->getUuid()]['mergedIntoOrderUuid']);
        self::assertNull($byUuid[$targetUuid]['mergedIntoOrderUuid']);
    }

    public function testInvoiceCreatedAfterEntityWasLoadedBlocksMerge(): void
    {
        $source = $this->source();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET invoice_id = ? WHERE id = ?', [UUID::v4(), $source->getId()]);
        try {
            $this->merger()->merge($this->counter, $this->user);
            self::fail('An invoice must prevent merging even when the managed entity is stale.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('existing payment invoice', $error->getMessage());
        }
        self::assertCount(1, $this->em->getRepository(Order::class)->findAll());
        self::assertSame([], $this->em->getRepository(OrderMerge::class)->findAll());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testUnacceptedStoreOrderCannotBeMerged(): void
    {
        $source = $this->source();
        $this->source();
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $source->getUuid()]);
        $lifecycle->setAcceptanceStatus(OrderStoreLifecycle::ACCEPTANCE_PENDING);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('accepted by the store');
        $this->merger()->merge($this->counter, $this->user);
    }

    public function testRealPaymentFlowCreatesInvoiceThatBlocksMerge(): void
    {
        $paid = $this->source();
        $this->source();
        $payment = self::getContainer()->get(OrderServiceInterface::class)->createPayment($paid, 'mock');
        self::assertSame($payment->invoice->getUuid(), $paid->getInvoiceId());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('existing payment invoice');
        $this->merger()->merge($this->counter, $this->user);
    }

    public function testCallerOutsideSessionCannotMerge(): void
    {
        $this->source();
        $other = (new User())->setEmail('other-merge@example.test')->setUsername('other-merge');
        $this->em->persist($other);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('eligible unpaid order');
        $this->merger()->merge($this->counter, $other);
    }

    public function testSingleOrderCannotBeMergedAgain(): void
    {
        $this->source();
        $this->source();
        $target = $this->merger()->merge($this->counter, $this->user);
        try {
            $this->merger()->merge($this->counter, $this->user);
            self::fail('A retry must not create a replacement for the sole remaining order.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('cannot be merged again', $error->getMessage());
        }
        self::assertCount(3, $this->em->getRepository(Order::class)->findAll());
        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
    }

    public function testClosedOrderDoesNotGrantMergePermission(): void
    {
        $closed = $this->source();
        $closed->setStatus(Order::STATUS_CANCELLED);
        $other = (new User())->setEmail('eligible-other@example.test')->setUsername('eligible-other');
        $this->em->persist($other);
        $this->source()->setUser($other);
        $this->source()->setUser($other);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('eligible unpaid order');
        $this->merger()->merge($this->counter, $this->user);
    }

    public function testMultiCounterOrderCannotBeMerged(): void
    {
        $source = $this->source();
        $this->source();
        $otherCounter = (new Counter())->setStoreUuid($this->context->storeUuid)->setCode('A02')->setName('Table A02');
        $this->em->persist($otherCounter);
        $this->em->flush();
        $binding = (new OrderCounter())->setCounter($otherCounter)->setStoreUuid($this->context->storeUuid)->setTradeOrderUuid($source->getUuid());
        $this->em->wrapInTransaction(fn () => $this->em->persist($binding));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('multiple counters');
        $this->merger()->merge($this->counter, $this->user);
    }

    public function testMergeFailureRollsBackTargetAndSourceChanges(): void
    {
        $source = $this->source();
        $this->source();
        $orders = $this->createStub(OrderServiceInterface::class);
        $orders->method('calculatePrices')->willReturn(new PriceCalculationResult(100, 'CNY', []));
        $orders->method('createOrder')->willReturnCallback(function (): never {
            $this->em->persist(new Order());
            $this->em->flush();
            throw new \RuntimeException('Target creation failed.');
        });
        try {
            $this->merger($orders)->merge($this->counter, $this->user);
            self::fail('Expected target creation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Target creation failed.', $error->getMessage());
        }
        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Order::class)->findAll());
        self::assertSame(Order::STATUS_CONFIRMED, $this->em->getRepository(Order::class)->findOneBy(['uuid' => $source->getUuid()])->getStatus());
    }

    public function testPaymentRejectsStaleConfirmedEntityAfterMerge(): void
    {
        $source = $this->source();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE id = ?', ['merged', $source->getId()]);
        $inner = $this->createMock(OrderServiceInterface::class);
        $inner->method('wrapInTransaction')->willReturnCallback(fn (callable $callback) => $this->em->wrapInTransaction($callback));
        $inner->expects(self::never())->method('createPayment');
        $service = new CounterTradeOrderService($inner, $this->em);
        $this->expectException(\DomainException::class);
        $service->createPayment($source);
    }

    public function testPaymentDelegatesOnlyInsideTransactionWithRefreshedOrder(): void
    {
        $source = $this->source();
        $invoiceUuid = UUID::v4();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET invoice_id = ? WHERE id = ?', [$invoiceUuid, $source->getId()]);
        $inner = $this->createMock(OrderServiceInterface::class);
        $inner->method('wrapInTransaction')->willReturnCallback(fn (callable $callback) => $this->em->wrapInTransaction($callback));
        $result = new PaymentResult(new Invoice(), 'pending');
        $inner->expects(self::once())->method('createPayment')->with($source, 'mock', ['test' => true])
            ->willReturnCallback(function (Order $order) use ($invoiceUuid, $result): PaymentResult {
                self::assertTrue($this->em->getConnection()->isTransactionActive());
                self::assertSame($invoiceUuid, $order->getInvoiceId());
                return $result;
            });
        self::assertSame($result, (new CounterTradeOrderService($inner, $this->em))->createPayment($source, 'mock', ['test' => true]));
    }

    public function testMergeRecordPreventsPaymentEvenIfAnotherWorkflowRestoresConfirmedStatus(): void
    {
        $source = $this->source();
        $this->em->persist(new OrderMerge($source->getUuid(), UUID::v4()));
        $this->em->flush();
        $inner = $this->createMock(OrderServiceInterface::class);
        $inner->method('wrapInTransaction')->willReturnCallback(fn (callable $callback) => $this->em->wrapInTransaction($callback));
        $inner->expects(self::never())->method('createPayment');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Merged source orders');
        (new CounterTradeOrderService($inner, $this->em))->createPayment($source);
    }

    public function testMySqlPaymentRequestsExclusiveOrderLockBeforeDelegation(): void
    {
        $source = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($repository);
        $locked = false;
        $em->expects(self::once())->method('refresh')->with($source, LockMode::PESSIMISTIC_WRITE)
            ->willReturnCallback(static function () use (&$locked): void { $locked = true; });
        $inner = $this->createMock(OrderServiceInterface::class);
        $inner->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $result = new PaymentResult(new Invoice(), 'pending');
        $inner->expects(self::once())->method('createPayment')->willReturnCallback(
            static function () use (&$locked, $result): PaymentResult {
                self::assertTrue($locked);
                return $result;
            },
        );
        self::assertSame($result, (new CounterTradeOrderService($inner, $em))->createPayment($source));
    }

    private function source(): Order
    {
        $order = (new Order())->setUser($this->user)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(100)
            ->setMetadata(['counterUuid' => $this->counter->getUuid()]);
        $order->addItem((new OrderItem())->setSpecificationUuid(UUID::v4())->setQuantity(1)->setUnitPrice(100)->setPrice(100));
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->persist($order);
            $this->em->persist((new OrderStoreLifecycle($order->getUuid(), $this->context->storeUuid))->markAccepted());
        });
        return $order;
    }

    private function merger(?OrderServiceInterface $orders = null): CounterOrderMergeService
    {
        if ($orders === null) {
            $actual = self::getContainer()->get(OrderServiceInterface::class);
            $orders = $this->createStub(OrderServiceInterface::class);
            $orders->method('calculatePrices')->willReturnCallback(static function (array $items): PriceCalculationResult {
                return new PriceCalculationResult(count($items) * 100, 'CNY', array_map(
                    static fn (array $item): array => [
                        'specificationUuid' => $item['specificationId'], 'quantity' => $item['quantity'], 'unitPrice' => 100, 'price' => 100,
                    ], $items,
                ));
            });
            $orders->method('createOrder')->willReturnCallback($actual->createOrder(...));
        }
        $resolver = $this->createStub(StoreContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context);
        return new CounterOrderMergeService(
            $this->em, $orders, $resolver, self::getContainer()->get('state_machine.order'),
            self::getContainer()->get(CounterSessionService::class),
        );
    }
}
