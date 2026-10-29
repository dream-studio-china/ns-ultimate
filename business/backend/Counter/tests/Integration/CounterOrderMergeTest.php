<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Inventory\Entity\Reservation;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Store\Entity\StoreOrder;
use App\Store\Entity\StoreOutboxMessage;
use App\Store\MessageHandler\TradeOrderCreatedHandler;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Message\StoreOrderFulfilledMessage;
use App\Trade\MessageHandler\StoreOrderFulfilledHandler;
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
use NsUltimate\Business\Inventory\Service\OrderInventoryAvailability;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Counter\Service\CounterOrderMergeService;
use NsUltimate\Business\Counter\Service\CounterSessionService;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
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

    public function testAuthenticatedApiMergePaymentAndFulfillmentFlow(): void
    {
        if (getenv('INVENTORY_ENABLED') === '1') {
            self::markTestSkipped('This flow validates the inventory-disabled deployment.');
        }
        $this->user->setRoles(['ROLE_ADMIN']);
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
        $this->em->persist(new Membership($store, $this->user->getUuid(), Membership::ROLE_OWNER));
        $product = (new Product($store))->setName('Integration coffee');
        $spec = (new Specification())->setProduct($product)->setName('Large')->setPrice(250);
        $this->em->persist($product);
        $this->em->persist($spec);
        $this->em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_STORE_CODE' => $store->getCode(),
            'CONTENT_TYPE' => 'application/json',
        ];
        $post = static function (string $path, array $body = []) use ($headers): \Symfony\Component\HttpFoundation\Response {
            return self::$kernel->handle(Request::create($path, 'POST', [], [], [], $headers, json_encode($body, JSON_THROW_ON_ERROR)));
        };
        $deliver = function (string $orderUuid): void {
            $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $orderUuid, 'topic' => 'trade.order.created.v1']);
            self::assertNotNull($event);
            self::getContainer()->get(TradeOrderCreatedHandler::class)(new TradeOrderCreatedMessage([
                'eventId' => $event->getEventId(), 'payload' => $event->getPayload(),
            ]));
        };

        $sourceUuids = [];
        foreach ([1, 2] as $quantity) {
            $response = $post('/api/v1/app/orders', [
                'items' => [['specificationId' => $spec->getUuid(), 'quantity' => $quantity]],
                'metadata' => ['counterUuid' => $this->counter->getUuid()],
            ]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
            $sourceUuid = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['uuid'];
            $sourceUuids[] = $sourceUuid;
            $deliver($sourceUuid);
            $response = $post('/api/v1/app/orders/'.$sourceUuid.'/confirm');
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        }

        $response = $post('/api/v1/app/counters/'.$this->counter->getUuid().'/orders/merge');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $target = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(Order::STATUS_CONFIRMED, $target['status']);
        self::assertSame(750, $target['totalAmount']);
        foreach ($sourceUuids as $sourceUuid) {
            $source = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $sourceUuid]);
            self::assertSame('merged', $source->getStatus());
            self::assertSame($target['uuid'], $source->getMetadata()['mergedIntoOrderUuid']);
            $response = $post('/api/v1/app/orders/'.$sourceUuid.'/payment', ['payment' => 'mock', 'autoPaid' => true]);
            self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        }
        $deliver($target['uuid']);
        $response = $post('/api/v1/app/orders/'.$target['uuid'].'/payment', ['payment' => 'mock', 'autoPaid' => true]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $targetOrder = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $target['uuid']]);
        self::assertSame(Order::STATUS_PAID, $targetOrder->getStatus());
        $response = $post('/api/v1/store/'.$store->getUuid().'/orders/'.$target['uuid'].'/fulfill');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $storeOrder = $this->em->getRepository(StoreOrder::class)->findOneBy(['tradeOrderUuid' => $target['uuid']]);
        $event = $this->em->getRepository(StoreOutboxMessage::class)->findOneBy(['topic' => 'store.order.fulfilled.v1', 'aggregateId' => $storeOrder->getUuid()]);
        self::assertNotNull($event);
        self::getContainer()->get(StoreOrderFulfilledHandler::class)(new StoreOrderFulfilledMessage([
            'eventId' => $event->getEventId(), 'payload' => $event->getPayload(),
        ]));
        $response = $post('/api/v1/manage/orders/'.$target['uuid'].'/fulfill');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response = $post('/api/v1/manage/orders/'.$target['uuid'].'/do/complete');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->em->clear();
        self::assertSame(Order::STATUS_COMPLETED, $this->em->getRepository(Order::class)->findOneBy(['uuid' => $target['uuid']])->getStatus());
    }

    public function testOrdinaryOrderCannotBeMarkedMergedDirectly(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $order = $this->source();
        self::assertFalse(self::getContainer()->get('state_machine.order')->can($order, 'merge'));
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
        $request = Request::create('/api/v1/manage/orders/'.$order->getUuid().'/do/merge', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $response = self::$kernel->handle($request);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
    }

    public function testMergedSourceItemsCannotBeUpdatedOrDeletedByAdmin(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $source = $this->source();
        $this->source();
        $item = $source->getItems()->first();
        $this->merger()->merge($this->counter, $this->user);
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
        $path = '/api/v1/manage/order-items/'.$item->getUuid();
        $response = self::$kernel->handle(Request::create($path, 'DELETE', [], [], [], $headers));
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $response = self::$kernel->handle(Request::create($path, 'PUT', [], [], [], $headers, '{"price":10}'));
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(OrderItem::class)->findOneBy(['uuid' => $item->getUuid()]));
    }

    public function testMergeMarksSourcesAndCreatesOneBoundTarget(): void
    {
        $sourceA = $this->source();
        $sourceB = $this->source();
        $target = $this->merger()->merge($this->counter, $this->user);

        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
        $outbox = $this->em->getRepository(TradeOutboxMessage::class)->findBy(['aggregateId' => $target->getUuid()]);
        self::assertCount(1, $outbox);
        self::assertSame('trade.order.created.v1', $outbox[0]->getTopic());
        self::assertFalse($outbox[0]->getPayload()['store']['requireInventory']);
        self::assertSame(200, $target->getTotalAmount());
        self::assertSame($this->user, $target->getUser());
        foreach ([$sourceA, $sourceB] as $source) {
            self::assertSame('merged', $source->getStatus());
            self::assertSame($target->getUuid(), $source->getMetadata()['mergedIntoOrderUuid']);
        }
        self::assertCount(2, $this->em->getRepository(OrderMerge::class)->findAll());
        self::assertTrue(self::getContainer()->get('state_machine.order')->can($target, 'cancel'));
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
        } catch (\InvalidArgumentException) {
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

    public function testUnacceptedStoreOrderCanBeMerged(): void
    {
        $source = $this->source();
        $this->source();
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $source->getUuid()]);
        $lifecycle->setAcceptanceStatus(OrderStoreLifecycle::ACCEPTANCE_PENDING);
        $this->em->flush();
        $target = $this->merger()->merge($this->counter, $this->user);
        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
    }

    public function testMergeCreatesStoreOrderWithoutRequestingInventory(): void
    {
        $this->context = new StoreContext($this->context->storeUuid, $this->context->storeCode, $this->context->storeName, requireInventory: true);
        $this->source(true);
        $this->source(true);
        $target = $this->merger()->merge($this->counter, $this->user);
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $target->getUuid()]);
        $container = self::getContainer();
        $handler = new TradeOrderCreatedHandler(
            $container->get(StoreRepository::class),
            $container->get(StoreConsumedEventRepository::class),
            $container->get(StoreTradeOrderCancellationRepository::class),
            $container->get(StoreOrderServiceInterface::class),
            $container->get(StoreOutboxServiceInterface::class),
            $this->em,
            true,
        );
        $message = new TradeOrderCreatedMessage(['eventId' => $event->getEventId(), 'payload' => $event->getPayload()]);
        $handler($message);
        $handler($message);
        $storeOrder = $this->em->getRepository(StoreOrder::class)->findOneBy(['tradeOrderUuid' => $target->getUuid()]);
        self::assertInstanceOf(StoreOrder::class, $storeOrder);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $storeOrder->getOperationalStatus());
        self::assertNull($storeOrder->getReservationId());
        self::assertSame([], $this->em->getRepository(StoreOutboxMessage::class)->findBy(['topic' => 'inventory.reservation.requested.v1']));
        self::assertCount(1, $this->em->getRepository(StoreOrder::class)->findBy(['tradeOrderUuid' => $target->getUuid()]));

        $container->get(OrderServiceInterface::class)->createPayment($target, 'mock', ['autoPaid' => true]);
        $container->get(StoreOrderServiceInterface::class)->fulfill($storeOrder);
        $outbox = $this->em->getRepository(StoreOutboxMessage::class)->findOneBy(['topic' => 'store.order.fulfilled.v1']);
        $container->get(StoreOrderFulfilledHandler::class)(new StoreOrderFulfilledMessage([
            'eventId' => $outbox->getEventId(), 'payload' => $outbox->getPayload(),
        ]));
        $workflow = $container->get('state_machine.order');
        $this->em->wrapInTransaction(function () use ($workflow, $target): void {
            $workflow->apply($target, 'fulfill');
            $workflow->apply($target, 'complete');
        });
        self::assertSame(Order::STATUS_COMPLETED, $target->getStatus());
    }

    public function testMergeRetainsStoreAcceptancePolicy(): void
    {
        $this->context = new StoreContext($this->context->storeUuid, $this->context->storeCode, $this->context->storeName, requireAcceptance: true, requireInventory: true);
        $this->source(true);
        $this->source(true);
        $target = $this->merger()->merge($this->counter, $this->user);
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $target->getUuid()]);
        self::assertSame(Order::STATUS_PENDING, $target->getStatus());
        self::assertTrue($event->getPayload()['store']['requireAcceptance']);
        self::assertFalse($event->getPayload()['store']['requireInventory']);
        $workflow = self::getContainer()->get('state_machine.order');
        self::assertFalse($workflow->can($target, 'confirm'));
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $target->getUuid()]);
        $lifecycle->markAccepted();
        $this->em->flush();
        $this->em->wrapInTransaction(fn () => $workflow->apply($target, 'confirm'));
        self::assertSame(Order::STATUS_CONFIRMED, $target->getStatus());
    }

    public function testInventoryConfirmationRequiresLiveMatchingReservation(): void
    {
        $order = $this->source();
        $order->setMetadata(['_store' => ['uuid' => $this->context->storeUuid, 'requireInventory' => true]]);
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
        $storeOrder = new StoreOrder($store, $order->getUuid(), $store->getCode(), $store->getName(), null, 'CNY', 100, []);
        $reservationId = UUID::v4();
        $storeOrder->awaitInventory($reservationId);
        $this->em->persist($storeOrder);
        $this->em->persist(new TradeOutboxMessage('trade.order.created.v1', 'trade_order', $order->getUuid(), [
            'store' => ['uuid' => $this->context->storeUuid, 'requireInventory' => true],
        ]));
        $this->em->flush();
        $guard = new OrderInventoryAvailability($this->em, true);
        self::assertFalse($guard->hasValidInventory($order));
        $order->setMetadata(['_store' => ['uuid' => $this->context->storeUuid, 'requireInventory' => false]]);
        self::assertFalse($guard->hasValidInventory($order), 'Client metadata must not disable a trusted inventory policy.');
        $reservation = new Reservation($reservationId, $this->context->storeUuid, $order->getUuid(), $storeOrder->getUuid(), str_repeat('a', 64), new \DateTimeImmutable('+30 minutes'));
        $this->em->persist($reservation);
        $this->em->flush();
        self::assertFalse($guard->hasValidInventory($order));
        $reservation->confirm();
        $this->em->flush();
        self::assertTrue($guard->hasValidInventory($order));
        $this->em->getConnection()->executeStatement('UPDATE inventory_reservation SET expires_at = ? WHERE reservation_id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $reservationId]);
        self::assertFalse($guard->hasValidInventory($order));
        $reservation->release();
        $this->em->flush();
        self::assertFalse($guard->hasValidInventory($order));
        self::assertTrue((new OrderInventoryAvailability($this->em, false))->hasValidInventory($order));
    }

    public function testEnabledInventoryGuardBlocksWorkflowWhenReservationIsReleased(): void
    {
        if (getenv('INVENTORY_ENABLED') !== '1') {
            self::markTestSkipped('This workflow test requires inventory to be enabled.');
        }
        $order = $this->source(true);
        $order->setStatus(Order::STATUS_PENDING);
        $this->em->flush();
        $workflow = self::getContainer()->get('state_machine.order');
        self::assertTrue($workflow->can($order, 'confirm'));
        $reservation = $this->em->getRepository(Reservation::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        $reservation->release();
        $this->em->flush();
        self::assertFalse($workflow->can($order, 'confirm'));
        $order->setStatus(Order::STATUS_CONFIRMED);
        $this->em->flush();
        try {
            self::getContainer()->get(OrderServiceInterface::class)->createPayment($order, 'mock');
            self::fail('A released reservation must prevent payment.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('inventory reservation', $error->getMessage());
        }
    }

    public function testPreviouslyMergedOrderCanBeMergedWithNewOrder(): void
    {
        $first = $this->source();
        $this->source();
        $target = $this->merger()->merge($this->counter, $this->user);
        $this->source();
        $next = $this->merger()->merge($this->counter, $this->user);
        self::assertSame('merged', $first->getStatus());
        self::assertSame('merged', $target->getStatus());
        self::assertSame($next->getUuid(), $target->getMetadata()['mergedIntoOrderUuid']);
        self::assertSame(Order::STATUS_CONFIRMED, $next->getStatus());
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

    public function testSettlementPaymentPrintsConfiguredRules(): void
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
        $account = new PrinterAccount('Test', 'test', '');
        $device = new PrinterDevice($account, 'Test', 'counter-test', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Test', PrinterDevice::KIND_RECEIPT, ['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Test']]]);
        $this->em->persist($account);
        $this->em->persist($device);
        $this->em->persist($template);
        $ruleIds = [];
        foreach (['order', 'settlement'] as $scene) {
            $rule = (new PrinterOrderRule())->setStoreUuid($store->getUuid())->setName($scene)->setEnabled(true)
                ->setEvents(['paid'])->setMatch('all')->setConditions([])
                ->setDeviceUuid($device->getUuid())->setTemplateUuid($template->getUuid())->setCopies(1);
            $this->em->persist($rule);
            $ruleIds[] = $rule->getUuid();
        }
        $this->em->flush();
        $sourceA = $this->source();
        $sourceB = $this->source();
        $target = $this->merger()->merge($this->counter, $this->user);

        $payment = self::getContainer()->get(OrderServiceInterface::class)->createPayment($target, 'mock', ['autoPaid' => true]);
        self::assertSame(Invoice::STATUS_PAID, $payment->invoice->getStatus());
        self::assertSame(Order::STATUS_PAID, $target->getStatus());
        self::assertSame('merged', $sourceA->getStatus());
        self::assertSame('merged', $sourceB->getStatus());
        self::assertNull($sourceA->getInvoiceId());
        self::assertNull($sourceB->getInvoiceId());
        $outbox = $this->em->getRepository(TradeOutboxMessage::class)->findBy(['aggregateId' => $target->getUuid()]);
        self::assertCount(1, $outbox);
        self::assertFalse($outbox[0]->getPayload()['store']['requireInventory']);
        $requests = $this->em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $target->getUuid()]);
        self::assertCount(2, $requests);
        self::assertEqualsCanonicalizing($ruleIds, array_map(static fn (PrinterOrderRequest $request): string => $request->getCaseKey(), $requests));
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
            self::assertStringContainsString('At least two', $error->getMessage());
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
        $service = new CounterTradeOrderService($inner, $this->em, new OrderInventoryAvailability($this->em, false));
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
        self::assertSame($result, (new CounterTradeOrderService($inner, $this->em, new OrderInventoryAvailability($this->em, false)))->createPayment($source, 'mock', ['test' => true]));
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
        (new CounterTradeOrderService($inner, $this->em, new OrderInventoryAvailability($this->em, false)))->createPayment($source);
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
        self::assertSame($result, (new CounterTradeOrderService($inner, $em, new OrderInventoryAvailability($em, false)))->createPayment($source));
    }

    private function source(bool $inventory = false): Order
    {
        $order = (new Order())->setUser($this->user)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(100)
            ->setMetadata(['counterUuid' => $this->counter->getUuid()]);
        $order->addItem((new OrderItem())->setSpecificationUuid(UUID::v4())->setQuantity(1)->setUnitPrice(100)->setPrice(100));
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->persist($order);
            $this->em->persist((new OrderStoreLifecycle($order->getUuid(), $this->context->storeUuid))->markAccepted());
        });
        if ($inventory) {
            $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->context->storeUuid]);
            $storeOrder = new StoreOrder($store, $order->getUuid(), $store->getCode(), $store->getName(), null, 'CNY', 100, []);
            $reservationId = UUID::v4();
            $storeOrder->awaitInventory($reservationId);
            $reservation = new Reservation($reservationId, $store->getUuid(), $order->getUuid(), $storeOrder->getUuid(), str_repeat('a', 64), new \DateTimeImmutable('+30 minutes'));
            $reservation->confirm();
            foreach ([$storeOrder, $reservation, new TradeOutboxMessage('trade.order.created.v1', 'trade_order', $order->getUuid(), ['store' => ['uuid' => $store->getUuid(), 'requireInventory' => true]])] as $entity) {
                $this->em->persist($entity);
            }
            $this->em->flush();
        }
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
            self::getContainer()->get(OrderInventoryAvailability::class),
        );
    }
}
