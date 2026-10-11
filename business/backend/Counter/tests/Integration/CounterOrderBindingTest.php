<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Console\Tester\CommandTester;

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
