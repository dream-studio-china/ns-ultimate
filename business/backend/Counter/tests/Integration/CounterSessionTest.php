<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Migrations\Version20261024010000;
use NsUltimate\Business\Counter\Service\CounterSessionService;
use NsUltimate\Business\Counter\Service\OrderCounterService;
use NsUltimate\Integration\Backend\Kernel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CounterSessionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testManualBindingsShareAnActiveSessionAndReopenAfterEnd(): void
    {
        $counter = $this->counter();
        $first = $this->bind($counter, Order::STATUS_COMPLETED);
        $second = $this->bind($counter, 'merged');
        self::assertSame($first->getCounterSession(), $second->getCounterSession());

        $sessions = new CounterSessionService($this->em);
        $this->em->wrapInTransaction(fn () => $sessions->end($counter));
        self::assertNotNull($first->getCounterSession()?->getEndedAt());
        $third = $this->bind($counter, Order::STATUS_COMPLETED);
        self::assertNotSame($first->getCounterSession(), $third->getCounterSession());
        self::assertSame(2, $this->em->getRepository(CounterSession::class)->count([]));
    }

    public function testMultipleBindingsInOneFlushShareAnUnflushedSession(): void
    {
        $counter = $this->counter();
        $this->em->wrapInTransaction(function () use ($counter): void {
            foreach ([UUID::v4(), UUID::v4()] as $uuid) {
                $this->em->persist((new OrderCounter())->setCounter($counter)->setStoreUuid($counter->getStoreUuid())->setTradeOrderUuid($uuid));
            }
        });
        self::assertSame(1, $this->em->getRepository(CounterSession::class)->count([]));
        self::assertSame(2, $this->em->getRepository(OrderCounter::class)->count([]));
    }

    public function testLegacyOrderCreationSnapshotsTheConfiguredDiningMode(): void
    {
        $modeUuid = UUID::v4();
        $ruleUuid = UUID::v4();
        $store = (new Store('legacy-dining-mode', 'Legacy dining mode'))->setSettings([
            'diningModes' => ['version' => 1, 'modes' => [[
                'uuid' => $modeUuid,
                'name' => 'Hotpot',
                'enabled' => true,
                'priority' => 10,
                'switchButtonLabel' => 'Hotpot',
                'availability' => ['allDay' => true, 'daysOfWeek' => [1, 2, 3, 4, 5, 6, 7], 'timeWindows' => []],
                'catalog' => ['categoryUuids' => [], 'productUuids' => []],
                'rules' => [],
                'checkoutRules' => [[
                    'uuid' => $ruleUuid,
                    'name' => 'Confirm guests',
                    'type' => 'confirm_participants',
                    'required' => true,
                    'categoryUuid' => '',
                ]],
            ]]],
        ]);
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('L01')->setName('Legacy table');
        foreach ([$store, $counter] as $entity) $this->em->persist($entity);
        $this->em->flush();

        $context = new StoreContext($store->getUuid(), $store->getCode(), $store->getName(), 'customer', $store->getCurrency());
        $orders = self::getContainer()->get(OrderServiceInterface::class);
        $first = $orders->createOrder([], null, 0, $store->getCurrency(), null, [
            'counterUuid' => $counter->getUuid(),
            'diningModeUuid' => $modeUuid,
        ], $context);
        $session = $this->em->getRepository(OrderCounter::class)->findOneBy(['tradeOrderUuid' => $first->getUuid()])?->getCounterSession();
        self::assertSame($modeUuid, $session?->getDiningModeUuid());
        self::assertSame([$ruleUuid], array_column($session?->getDiningModeSnapshot()['checkoutRules'] ?? [], 'uuid'));

        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $store->getUuid()]);
        $settings = $store->getSettings();
        $settings['diningModes']['modes'][0]['name'] = 'Changed after ordering';
        $store->setSettings($settings);
        $this->em->flush();
        $addition = $orders->createOrder([], null, 0, $store->getCurrency(), null, ['counterUuid' => $counter->getUuid()], $context);
        $additionSession = $this->em->getRepository(OrderCounter::class)->findOneBy(['tradeOrderUuid' => $addition->getUuid()])?->getCounterSession();
        self::assertSame($session?->getUuid(), $additionSession?->getUuid());
        self::assertSame('Hotpot', $additionSession?->getDiningModeSnapshot()['name'] ?? null);
        self::assertSame([$ruleUuid], array_column($additionSession?->getDiningModeSnapshot()['checkoutRules'] ?? [], 'uuid'));
    }

    public function testAdminAndStorePartialCreatesAndAutomaticBindingUseTheSameSession(): void
    {
        $admin = (new User())->setEmail('session-admin@example.test')->setUsername('session-admin')->setRoles(['ROLE_ADMIN']);
        $store = new Store('session-api', 'Session API');
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Session API');
        $orders = [new Order(), new Order()];
        foreach ([$admin, $store, $counter, ...$orders] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new Membership($store, $admin->getUuid(), Membership::ROLE_OWNER));
        foreach ($orders as $order) {
            $this->em->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));
        }
        $this->em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        foreach (['/api/v1/manage/order-counters', '/api/v1/store/'.$store->getUuid().'/order-counters'] as $index => $path) {
            $response = self::$kernel->handle(Request::create($path.'?@partial=1', 'POST', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
            ], json_encode(['counterUuid' => $counter->getUuid(), 'tradeOrderUuid' => $orders[$index]->getUuid()], JSON_THROW_ON_ERROR)));
            self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        }
        $automatic = (new Order())->setMetadata(['counterUuid' => $counter->getUuid()]);
        $this->em->wrapInTransaction(function () use ($automatic, $store): void {
            $this->em->persist($automatic);
            $this->em->persist(new OrderStoreLifecycle($automatic->getUuid(), $store->getUuid()));
        });
        self::assertSame(3, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter_order'));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT counter_session_id) FROM counter_order'));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter_order WHERE counter_session_id IS NULL'));
    }

    public function testAssignmentRejectsAnImplicitTransactionBeforeQueryingSessions(): void
    {
        $counter = $this->counter();
        $this->em->persist((new OrderCounter())->setCounter($counter)->setTradeOrderUuid(UUID::v4()));
        try {
            $this->em->flush();
            self::fail('An implicit transaction must not permit session assignment.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('explicit transaction', $error->getMessage());
        }
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter_session'));
    }

    public function testEndRejectsDraftUnknownAndMissingOrders(): void
    {
        foreach ([Order::STATUS_DRAFT, Order::STATUS_PENDING, Order::STATUS_CONFIRMED, Order::STATUS_PAID, Order::STATUS_FULFILLED, 'future-status', null] as $status) {
            $counter = $this->counter();
            $binding = $this->bind($counter, $status);
            $this->em->getConnection()->beginTransaction();
            try {
                (new CounterSessionService($this->em))->end($counter);
                self::fail('Nonterminal or missing orders must prevent session end.');
            } catch (\DomainException) {
                self::assertNull($binding->getCounterSession()?->getEndedAt());
            } finally {
                $this->em->getConnection()->rollBack();
            }
        }
    }

    public function testEveryTerminalStatusAllowsSessionEnd(): void
    {
        foreach ([Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED, 'merged'] as $status) {
            $counter = $this->counter();
            $binding = $this->bind($counter, $status);
            $this->em->wrapInTransaction(fn () => (new CounterSessionService($this->em))->end($counter));
            self::assertNotNull($binding->getCounterSession()?->getEndedAt());
        }
    }

    public function testDatabaseRejectsTwoActiveSessionsEvenWithoutServiceLocks(): void
    {
        $counter = $this->counter();
        $connection = $this->em->getConnection();
        $values = ['uuid' => UUID::v4(), 'counter_id' => $counter->getId(), 'active_counter_uuid' => $counter->getUuid(), 'started_at' => '2026-10-24 01:00:00'];
        $connection->insert('counter_session', $values);
        $values['uuid'] = UUID::v4();
        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('counter_session', $values);
    }

    public function testMigrationQueuesBackfillWithoutDryRunWritesAndPreservesEveryBinding(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261024010000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('CREATE TABLE counter (id INTEGER PRIMARY KEY, uuid VARCHAR(36) NOT NULL)');
        $connection->executeStatement('CREATE TABLE counter_order (id INTEGER PRIMARY KEY, counter_id INTEGER NOT NULL)');
        foreach ([1, 2, 3] as $id) {
            $connection->insert('counter', ['id' => $id, 'uuid' => UUID::v4()]);
        }
        foreach ([1 => 1, 2 => 1, 3 => 2] as $id => $counterId) {
            $connection->insert('counter_order', ['id' => $id, 'counter_id' => $counterId]);
        }
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261024010000($connection, new NullLogger());
        $migration->up($schema);
        $migration->postUp($schema);
        self::assertFalse($connection->createSchemaManager()->tablesExist(['counter_session']));
        self::assertSame(3, $connection->fetchOne('SELECT COUNT(*) FROM counter_order'));
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM counter_session'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM counter_order WHERE counter_session_id IS NULL'));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(DISTINCT counter_session_id) FROM counter_order WHERE counter_id = 1'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM counter_order b JOIN counter_session s ON s.id = b.counter_session_id WHERE b.counter_id <> s.counter_id'));
        $down = new Version20261024010000($connection, new NullLogger());
        $down->down($connection->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame(3, $connection->fetchOne('SELECT COUNT(*) FROM counter_order'));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['counter_session']));
        $connection->close();
    }

    public function testMigrationCompilesMysqlDdlWithoutConnectingOrRunningBackfill(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261024010000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.32']);
        $migration = new Version20261024010000($connection, new NullLogger());
        $migration->up(new Schema());
        $migration->postUp(new Schema());
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
        self::assertStringContainsString('AUTO_INCREMENT', $sql);
        self::assertStringContainsString('UNIQUE INDEX uniq_counter_session_active (active_counter_uuid)', $sql);
        self::assertStringContainsString('INSERT INTO counter_session', $sql);
        self::assertStringContainsString('UPDATE counter_order SET counter_session_id', $sql);
        self::assertFalse($migration->isTransactional());
        self::assertFalse($connection->isConnected());
    }

    private function counter(): Counter
    {
        $counter = (new Counter())->setStoreUuid(UUID::v4())->setCode(UUID::v4())->setName('Session test');
        $this->em->persist($counter);
        $this->em->flush();
        return $counter;
    }

    private function bind(Counter $counter, ?string $status): OrderCounter
    {
        $uuid = UUID::v4();
        if ($status !== null) {
            $order = new Order();
            $order->setStatus($status);
            $this->em->persist($order);
            $this->em->flush();
            $uuid = $order->getUuid();
        }
        $binding = (new OrderCounter())->setCounter($counter)->setStoreUuid($counter->getStoreUuid())->setTradeOrderUuid($uuid);
        self::getContainer()->get(OrderCounterService::class)->update($binding);
        return $binding;
    }
}
