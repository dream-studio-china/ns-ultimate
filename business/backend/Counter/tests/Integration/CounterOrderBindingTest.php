<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
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
        self::assertInstanceOf(Route::class, $routes->get('app-store-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('manage-order-counters-list'));
        self::assertInstanceOf(Route::class, $routes->get('app-order-counters-list'));
        self::assertContains(Counter::class, $entityNames);
        self::assertContains(OrderCounter::class, $entityNames);
        foreach ([
            'business-counters-list' => '/manage/counters',
            'manage-order-counters-list' => '/manage/order-counters',
            'store-counters-list' => '/store/{scopeId}/counters',
            'store-order-counters-list' => '/store/{scopeId}/order-counters',
            'app-store-counters-list' => '/app/stores/{storeUuid}/counters',
            'app-order-counters-list' => '/app/orders/{orderUuid}/counters',
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

        self::assertTrue($schemaManager->tablesExist(['counter', 'counter_order']));
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
        $entityManager->flush();

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
