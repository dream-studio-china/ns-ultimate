<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Business\Counter\Migrations\Version20261011020000;
use NsUltimate\Integration\Backend\Kernel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class CounterOrderBindingTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($entityManager->getConnection()->createSchemaManager()->listTableNames() !== []) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCounterManagementRouteAndEntitiesAreRegistered(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        $entityNames = array_map(static fn ($item): string => $item->getName(), $metadata);

        self::assertInstanceOf(Route::class, $routes->get('business-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('store-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('store-order-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('app-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('manage-order-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('app-counter-orders-list'));
        self::assertInstanceOf(Route::class, $routes->get('app-counter-orders-merge'));
        self::assertInstanceOf(Route::class, $routes->get('store-counters-session-end'));
        self::assertContains(Counter::class, $entityNames);
        self::assertContains(OrderCounter::class, $entityNames);
        self::assertContains(CounterSession::class, $entityNames);
        self::assertContains(OrderMerge::class, $entityNames);
        foreach ([
            'business-counters-list' => '/manage/counters',
            'manage-order-counters-list' => '/manage/order-counters',
            'store-counters-list' => '/store/{scopeId}/counters',
            'store-order-counters-list' => '/store/{scopeId}/order-counters',
            'app-counters-list' => '/app/counters',
            'app-counter-orders-list' => '/app/counters/{counterUuid}/orders',
            'app-counter-orders-merge' => '/app/counters/{counterUuid}/orders/merge',
            'store-counters-session-end' => '/store/{scopeId}/counters/{counterUuid}/sessions/end',
        ] as $name => $path) {
            self::assertSame($path, $routes->get($name)?->getPath());
            self::assertSame('/api/v1'.$path, $routes->get('api-v1-'.$name)?->getPath());
        }
    }

    public function testCounterMetadataLabelsAreTranslatedForSupportedLocales(): void
    {
        $translator = self::getContainer()->get('translator');
        foreach ([
            'zh' => ['台位', '订单台位绑定', '台位编号快照'],
            'zh_Hant' => ['桌台', '訂單桌台綁定', '桌台編號快照'],
            'ja' => ['テーブル', '注文テーブル紐付け', 'テーブル番号スナップショット'],
            'en' => ['Counter', 'Order Counter', 'Counter code snapshot'],
        ] as $locale => [$counter, $binding, $counterCodeSnapshot]) {
            self::assertSame($counter, $translator->trans('Counter', [], 'messages', $locale));
            self::assertSame($binding, $translator->trans('OrderCounter', [], 'messages', $locale));
            self::assertSame($counterCodeSnapshot, $translator->trans('Counter code snapshot', [], 'messages', $locale));
        }
    }

    public function testEveryCounterEntityFieldHasBackendTranslations(): void
    {
        $metadataFactory = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory();
        $translator = self::getContainer()->get('translator');
        foreach ([Counter::class, OrderCounter::class] as $entityClass) {
            $metadata = $metadataFactory->getMetadataFor($entityClass);
            $fields = array_merge(array_keys($metadata->fieldMappings), array_keys($metadata->associationMappings));
            foreach ($fields as $field) {
                $parts = preg_split('/(?=[A-Z])/', $field);
                $plainText = ucfirst(strtolower(ucwords(implode(' ', is_array($parts) ? $parts : [$field]))));
                foreach (['zh', 'zh_Hant', 'ja'] as $locale) {
                    self::assertNotSame(
                        $plainText,
                        $translator->trans($plainText, [], 'messages', $locale),
                        sprintf('Missing %s translation for %s::$%s.', $locale, $entityClass, $field),
                    );
                }
            }
        }
    }

    public function testCounterEntitiesUseNamesForStringDisplay(): void
    {
        $counter = (new Counter())->setCode('A01')->setName('Window seat');
        self::assertSame('Window seat', (string) $counter);

        $binding = (new OrderCounter())->setCounter($counter);
        self::assertSame('Window seat', (string) $binding);

        $unnamedCounter = (new Counter())->setCode('A02');
        self::assertSame('', (string) $unnamedCounter);
        self::assertSame('A02', (string) (new OrderCounter())->setCounter($unnamedCounter));
    }

    public function testCounterEntitiesUseUnprefixedTableNames(): void
    {
        $schemaManager = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->createSchemaManager();

        self::assertTrue($schemaManager->tablesExist(['counter', 'counter_order', 'counter_session', 'counter_order_merge']));
        self::assertFalse($schemaManager->tablesExist(['business_counter', 'business_order_counter']));
    }

    public function testTableRenameMigrationPreservesLegacyCounterTables(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261011020000.php';
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DROP TABLE counter_order');
        $connection->executeStatement('DROP TABLE counter');
        $connection->executeStatement('CREATE TABLE business_counter (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, code VARCHAR(50) NOT NULL, enabled BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE business_order_counter (id INTEGER PRIMARY KEY AUTOINCREMENT, trade_order_uuid VARCHAR(36) NOT NULL, counter_id INTEGER NOT NULL, store_uuid VARCHAR(36) NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_business_counter_uuid ON business_counter (uuid)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_business_counter_store_code ON business_counter (store_uuid, code)');
        $connection->executeStatement('CREATE INDEX idx_business_counter_store_enabled ON business_counter (store_uuid, enabled)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_business_order_counter_pair ON business_order_counter (trade_order_uuid, counter_id)');
        $connection->executeStatement('CREATE INDEX idx_business_order_counter_store ON business_order_counter (store_uuid)');
        $connection->executeStatement("INSERT INTO business_counter (uuid, store_uuid, code, enabled) VALUES ('counter-uuid', 'store-uuid', 'A01', 1)");

        $migration = new Version20261011020000($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $schemaManager = $connection->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist(['counter', 'counter_order']));
        self::assertFalse($schemaManager->tablesExist(['business_counter', 'business_order_counter']));
        self::assertSame('A01', $connection->fetchOne('SELECT code FROM counter WHERE id = 1'));
        $counterIndexes = array_keys($schemaManager->listTableIndexes('counter'));
        self::assertContains('idx_counter_store_enabled', $counterIndexes);
        self::assertContains('uniq_counter_store_code', $counterIndexes);
        self::assertContains('uniq_counter_uuid', $counterIndexes);
        $orderCounterIndexes = array_keys($schemaManager->listTableIndexes('counter_order'));
        self::assertContains('idx_counter_order_store', $orderCounterIndexes);
        self::assertContains('uniq_counter_order_pair', $orderCounterIndexes);
    }

    public function testPublicCounterDetailExposesOnlyEnabledCountersOfActiveStoresWithoutId(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $store = new Store('public-counter', 'Public counter store');
        $suspendedStore = new Store('suspended-counter', 'Suspended store');
        $suspendedStore->suspend();
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Table A01')->setCapacity(4)->setSort(3)->setMetadata(['color' => 'red']);
        $disabled = (new Counter())->setStoreUuid($store->getUuid())->setCode('A02')->setName('Table A02')->setEnabled(false);
        $suspended = (new Counter())->setStoreUuid($suspendedStore->getUuid())->setCode('B01')->setName('Suspended table');
        foreach ([$store, $suspendedStore, $counter, $disabled, $suspended] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $get = static function (string $path) {
            return self::$kernel->handle(Request::create($path, 'GET'));
        };

        $response = $get('/api/v1/public/counters/'.$counter->getUuid());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('id', $payload['data']);
        self::assertSame([
            'uuid' => $counter->getUuid(),
            'storeUuid' => $store->getUuid(),
            'code' => 'A01',
            'name' => 'Table A01',
            'capacity' => 4,
            'sort' => 3,
            'metadata' => ['color' => 'red'],
        ], $payload['data']);

        self::assertSame(404, $get('/api/v1/public/counters/'.$disabled->getUuid())->getStatusCode());
        self::assertSame(404, $get('/api/v1/public/counters/'.$suspended->getUuid())->getStatusCode());
        self::assertSame(404, $get('/api/v1/public/counters/1')->getStatusCode());

        $routes = self::getContainer()->get('router')->getRouteCollection();
        self::assertInstanceOf(Route::class, $routes->get('api-v1-public-counters-detail'));
        self::assertSame('/api/v1/public/counters/{id}', $routes->get('api-v1-public-counters-detail')?->getPath());
    }

    public function testAppCounterListUsesStoreCodeHeaderInsteadOfStorePathParameter(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('app-counter-context@example.test')->setUsername('app-counter-context');
        $store = new Store('app-counter-context', 'App counter context');
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Table A01');
        foreach ([$user, $store, $counter] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);

        $request = Request::create('/api/v1/app/counters', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_STORE_CODE' => $store->getCode(),
        ]);
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['data']);
        self::assertSame($counter->getUuid(), $payload['data'][0]['uuid']);

        $request = Request::create('/api/v1/app/counters', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['data']);
    }

    public function testAppCounterOrdersListsNonClosedOrdersAcrossDaysInActiveSession(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('app-counter-orders@example.test')->setUsername('app-counter-orders');
        $store = new Store('app-counter-orders', 'App counter orders', 'Asia/Shanghai');
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Table A01');
        $openOrder = new Order();
        $openOrder->setStatus(Order::STATUS_PENDING);
        $openOrder->addItem((new OrderItem())
            ->setProductSnapshot(['name' => 'Coffee'])
            ->setSpecificationTitle('Latte')
            ->setQuantity(2)
            ->setUnitPrice(450)
            ->setCost(100));
        $completedOrder = new Order();
        $completedOrder->setStatus(Order::STATUS_COMPLETED);
        $yesterdayOrder = new Order();
        $yesterdayOrder->setStatus(Order::STATUS_PENDING);

        foreach ([$user, $store, $counter, $openOrder, $completedOrder, $yesterdayOrder] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        foreach ([$openOrder, $completedOrder, $yesterdayOrder] as $order) {
            $entityManager->persist((new OrderCounter())
                ->setTradeOrderUuid($order->getUuid())
                ->setStoreUuid($store->getUuid())
                ->setCounter($counter));
        }
        $entityManager->wrapInTransaction(static function (): void {});

        $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone($store->getTimezone())))
            ->modify('-1 day')
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $entityManager->getConnection()->executeStatement(
            'UPDATE trade_order SET created_at = ? WHERE uuid = ?',
            [$yesterday->format('Y-m-d H:i:s'), $yesterdayOrder->getUuid()],
        );

        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        $request = Request::create('/api/v1/app/counters/'.$counter->getUuid().'/orders', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $response = self::$kernel->handle($request);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing([$openOrder->getUuid(), $yesterdayOrder->getUuid()], array_column($payload['data'], 'uuid'));
        $openOrderData = array_column($payload['data'], null, 'uuid')[$openOrder->getUuid()];
        self::assertSame([
            'productName' => 'Coffee',
            'specificationName' => 'Latte',
            'quantity' => 2,
            'unitPrice' => 450,
            'price' => 900,
        ], $openOrderData['items'][0]);
        self::assertArrayNotHasKey('cost', $openOrderData['items'][0]);
        self::assertArrayNotHasKey('profit', $openOrderData['items'][0]);
    }

    public function testAdminApiStoresCounterSortAndMetadata(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('counter-sort@example.test')->setUsername('counter-sort')->setRoles(['ROLE_ADMIN']);
        $store = new Store('counter-sort', 'Counter sort store');
        foreach ([$admin, $store] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $request = static function (string $method, string $path, ?array $content = null) use ($token): array {
            $request = Request::create($path, $method, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], $content === null ? null : json_encode($content, JSON_THROW_ON_ERROR));
            $response = self::$kernel->handle($request);
            $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertLessThan(300, $response->getStatusCode(), $response->getContent());

            return $body;
        };

        $counter = $request('POST', '/api/v1/manage/counters', [
            'storeUuid' => $store->getUuid(),
            'code' => 'A01',
            'name' => 'Table A01',
            'sort' => 5,
            'metadata' => ['color' => 'red'],
            'enabled' => true,
        ])['data'];
        self::assertSame(5, $counter['sort']);
        self::assertSame(['color' => 'red'], $counter['metadata']);

        $ordered = $request('GET', '/api/v1/manage/counters?'.http_build_query([
            '@order' => 'entity.sort|ASC',
        ]))['data'];
        self::assertSame(['A01'], array_map(static fn (array $row): string => $row['code'], $ordered));

        $request('PUT', '/api/v1/manage/counters/'.$counter['id'], ['sort' => 2, 'metadata' => null]);
        $updated = $request('GET', '/api/v1/manage/counters/'.$counter['id'])['data'];
        self::assertSame(2, $updated['sort']);
        self::assertNull($updated['metadata']);

        foreach ([-1, 'high'] as $invalidSort) {
            $request = Request::create('/api/v1/manage/counters/'.$counter['id'], 'PUT', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], json_encode(['sort' => $invalidSort], JSON_THROW_ON_ERROR));
            self::assertSame(400, self::$kernel->handle($request)->getStatusCode());
        }
    }

    public function testOrderMetadataCreatesBindingsForMultipleSameStoreCountersInOrderTransaction(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $storeUuid = UUID::v4();
        $counterA = (new Counter())->setStoreUuid($storeUuid)->setCode('A01')->setName('Table A01');
        $counterB = (new Counter())->setStoreUuid($storeUuid)->setCode('A02')->setName('Table A02');
        $entityManager->persist($counterA);
        $entityManager->persist($counterB);
        $entityManager->flush();

        $order = (new Order())->setMetadata([
            '_store' => ['uuid' => $storeUuid],
            'counterUuids' => [$counterA->getUuid(), $counterB->getUuid()],
        ]);
        $entityManager->persist($order);
        $entityManager->persist(new OrderStoreLifecycle($order->getUuid(), $storeUuid));
        $entityManager->wrapInTransaction(static function (): void {});

        $bindings = $entityManager->getRepository(OrderCounter::class)->findBy(['tradeOrderUuid' => $order->getUuid()]);
        self::assertCount(2, $bindings);
        self::assertSame(['A01', 'A02'], array_map(static fn (OrderCounter $binding): string => $binding->getCounterCodeSnapshot(), $bindings));
    }

    public function testAdminApiCanCreateListAndDeleteCounterBindings(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('counter-admin@example.test')->setUsername('counter-admin')->setRoles(['ROLE_ADMIN']);
        $store = new Store('counter-test', 'Counter test store');
        $order = new Order();
        foreach ([$admin, $store, $order, new OrderStoreLifecycle($order->getUuid(), $store->getUuid())] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $request = static function (string $method, string $path, ?array $content = null) use ($token): array {
            $request = Request::create($path, $method, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], $content === null ? null : json_encode($content, JSON_THROW_ON_ERROR));
            $response = self::$kernel->handle($request);
            self::assertLessThan(300, $response->getStatusCode(), $response->getContent());
            if ($response->getStatusCode() === 204) {
                return [];
            }
            $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $body['code'], $response->getContent());
            return $body;
        };
        $counter = $request('POST', '/api/v1/manage/counters', [
            'storeUuid' => $store->getUuid(), 'code' => 'A01', 'name' => 'Table A01', 'enabled' => true,
        ])['data'];
        self::assertSame('A01', $counter['code']);
        $request('PUT', '/api/v1/manage/counters/'.$counter['id'], ['name' => 'Renamed table']);
        self::assertCount(1, $request('GET', '/api/v1/manage/counters')['data']);
        $counterFilterPath = '/api/v1/manage/counters?'.http_build_query([
            '@filter' => "entity.getStoreUuid() == '".$store->getUuid()."'",
        ]);
        self::assertCount(1, $request('GET', $counterFilterPath)['data']);
        self::assertCount(0, $request('GET', '/api/v1/manage/counters?'.http_build_query([
            '@filter' => "entity.getStoreUuid() == '".UUID::v4()."'",
        ]))['data']);
        $binding = $request('POST', '/api/v1/manage/order-counters', [
            'tradeOrderUuid' => $order->getUuid(), 'counterUuid' => $counter['uuid'],
        ])['data'];
        self::assertSame('Renamed table', $binding['counterNameSnapshot']);
        self::assertCount(1, $request('GET', '/api/v1/manage/order-counters')['data']);
        self::assertCount(1, $request('GET', '/api/v1/manage/order-counters?'.http_build_query([
            '@filter' => "entity.getStoreUuid() == '".$store->getUuid()."' && entity.getTradeOrderUuid() == '".$order->getUuid()."'",
        ]))['data']);
        self::assertCount(0, $request('GET', '/api/v1/manage/order-counters?'.http_build_query([
            '@filter' => "entity.getTradeOrderUuid() == '".UUID::v4()."'",
        ]))['data']);
        $request('GET', '/api/v1/manage/order-counters/'.$binding['id']);
        $request('DELETE', '/api/v1/manage/order-counters/'.$binding['id']);
        $request('DELETE', '/api/v1/manage/counters/'.$counter['id']);
    }

    public function testOrderWithoutCounterMetadataIsUnaffected(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $order = new Order();
        $entityManager->persist($order);
        $entityManager->flush();

        self::assertSame([], $entityManager->getRepository(OrderCounter::class)->findBy(['tradeOrderUuid' => $order->getUuid()]));
    }

    public function testCounterFromAnotherStoreRejectsOrderInsert(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid(UUID::v4())->setCode('B01')->setName('Other store table');
        $entityManager->persist($counter);
        $entityManager->flush();

        $order = (new Order())->setMetadata([
            // Deliberately forged metadata: only OrderStoreLifecycle is trusted.
            '_store' => ['uuid' => $counter->getStoreUuid()],
            'counterUuid' => $counter->getUuid(),
        ]);
        $entityManager->persist($order);
        $entityManager->persist(new OrderStoreLifecycle($order->getUuid(), UUID::v4()));

        try {
            $entityManager->flush();
            self::fail('Expected a forged store metadata mismatch to reject the order.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM trade_order WHERE uuid = ?',
                [$order->getUuid()],
            ));
        }
    }

    public function testAuthorizationSeedRegistersScopedCounterPermissionsAndRole(): void
    {
        $command = self::getContainer()->get(\NsUltimate\Business\Counter\Command\SeedCounterAuthorizationCommand::class);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $permission = $entityManager->getRepository(Permission::class)->findOneBy(['code' => 'store:counter:create']);
        $role = $entityManager->getRepository(Role::class)->findOneBy(['code' => 'store_counter_manager']);
        self::assertInstanceOf(Permission::class, $permission);
        self::assertInstanceOf(Role::class, $role);
        self::assertTrue($role->hasPermission('store:counter:create'));
        self::assertTrue($role->hasPermission('store:order_counter:delete'));
    }
}
