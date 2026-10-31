<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Store\Service\StoreOrderServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Business\Store\Entity\StaffOperation;
use NsUltimate\Business\Store\Service\StaffOrderPricing;
use NsUltimate\Business\Store\Service\StaffOrderService;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CounterOperationsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Counter $counter;
    private Specification $spec;
    private User $user;
    private string $token;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->store = new Store('counter-session', 'Counter session test');
        $this->store->setSettings(['order' => ['requireAcceptance' => true]]);
        $this->counter = (new Counter())->setStoreUuid($this->store->getUuid())->setCode('A01')->setName('Window table');
        $product = (new Product($this->store))->setName('Lunch');
        $this->spec = (new Specification())->setProduct($product)->setName('Regular')->setPrice(1590);
        $this->user = (new User())->setUsername('counter-staff')->setEmail('counter-staff@example.test');
        $role = new Role('counter-session-test', 'Counter session test', Role::SCOPE_STORE);
        foreach ([
            'counter_session:read', 'counter_session:update', 'counter_session:end',
            'counter_order:read', 'counter_order:create', 'counter_order:replace', 'counter_order:cancel', 'counter_order:merge',
        ] as $name) {
            [$resource, $action] = explode(':', $name);
            $permission = new Permission('store:'.$name, 'store', $resource, $action, $name);
            $this->em->persist($permission); $role->addPermission($permission);
        }
        foreach ([$this->store, $this->counter, $product, $this->spec, $this->user, $role,
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
    }

    public function testCustomerSessionQuoteCreateAndAddWithDurableReplay(): void
    {
        $initial = $this->view();
        self::assertNull($initial['session']);
        $this->startCustomerSession();
        $first = $this->place();
        self::assertSame($first, $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', $first['_input'])) + ['_input' => $first['_input']]);
        self::assertSame(409, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', [...$first['_input'], 'notes' => 'Changed retry'])->getStatusCode());
        $this->project($first['orderUuid']);
        self::assertTrue($this->orderByUuid($first['orderUuid'])['canReplace']);
        $second = $this->place();
        $this->project($second['orderUuid']);
        $view = $this->view();
        self::assertCount(3, $view['orders']);
        self::assertSame(6360, $view['openAmount']);
        self::assertFalse($view['canEnd']);
        self::assertSame(409, $this->request('POST', '/counters/'.$this->counter->getUuid().'/session/end', ['commandUuid' => UUID::v4(), 'version' => $view['version']])->getStatusCode());
        self::assertSame(2, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testStaffOrderMetadataIsBoundToQuoteAndPreservedOnReplacement(): void
    {
        $this->startCustomerSession();
        $base = '/counters/'.$this->counter->getUuid().'/orders/';
        $input = [...$this->quoteInput(), 'metadata' => ['source' => 'staff', 'custom' => ['label' => 'priority']]];
        $quote = $this->data($this->request('POST', $base.'quote', $input));
        $body = [...$input, 'quoteToken' => $quote['quoteToken'], 'commandUuid' => UUID::v4()];
        self::assertSame(409, $this->request('POST', $base.'create', [...$body, 'metadata' => ['source' => 'changed']])->getStatusCode());
        $created = $this->data($this->request('POST', $base.'create', $body));
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $created['orderUuid']]);
        self::assertSame('staff', $order->getMetadata()['source']);
        self::assertSame(['label' => 'priority'], $order->getMetadata()['custom']);
        self::assertSame($this->counter->getUuid(), $order->getMetadata()['counterUuid']);
        $this->project($created['orderUuid']);
        $replacement = [...$this->quoteInput(), 'replaceOrderUuid' => $created['orderUuid'], 'metadata' => ['source' => 'updated']];
        $newQuote = $this->data($this->request('POST', $base.'quote', $replacement));
        $result = $this->data($this->request('POST', $base.'replace', [...$replacement, 'quoteToken' => $newQuote['quoteToken'], 'commandUuid' => UUID::v4(), 'reason' => 'Update order']));
        $updated = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']]);
        self::assertSame('updated', $updated->getMetadata()['source']);
        self::assertSame(['label' => 'priority'], $updated->getMetadata()['custom']);
        self::assertSame($this->counter->getUuid(), $updated->getMetadata()['counterUuid']);
    }

    public function testStaffOrderMetadataRejectsInvalidAndReservedFields(): void
    {
        $this->startCustomerSession();
        $path = '/counters/'.$this->counter->getUuid().'/orders/quote';
        foreach ([null, 'text', [1, 2], ['_store' => []], ['counterUuid' => UUID::v4()], ['counterUuids' => []], ['counterSessionUuid' => UUID::v4()], ['counterSettlement' => true], ['mergedIntoOrderUuid' => UUID::v4()]] as $metadata) {
            self::assertSame(422, $this->request('POST', $path, [...$this->quoteInput(), 'metadata' => $metadata])->getStatusCode());
        }
    }

    public function testStaffMergePreviewConfirmAndReplayWithExplicitOwner(): void
    {
        $this->startCustomerSession();
        $first = $this->place(); $this->project($first['orderUuid']);
        $second = $this->place(); $this->project($second['orderUuid']);
        foreach ([$first, $second] as $source) $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['confirmed', $source['orderUuid']]);
        $owner = (new User())->setUsername('merge-owner')->setEmail('merge-owner@example.test');
        $this->em->persist($owner); $this->em->flush(); $ownerUuid = $owner->getUuid();
        $this->em->clear();
        $base = '/counters/'.$this->counter->getUuid().'/merge/';
        $input = ['version' => $this->view()['version'], 'user' => $ownerUuid];
        $quote = $this->data($this->request('POST', $base.'preview', $input));
        self::assertCount(2, $quote['sourceOrderUuids']);
        self::assertSame(6360, $quote['totalAmount']);
        self::assertSame(6360, $quote['sourceTotalAmount']);
        self::assertSame(0, $quote['differenceAmount']);
        $body = [...$input, 'quoteToken' => $quote['quoteToken'], 'commandUuid' => UUID::v4()];
        self::assertSame(409, $this->request('POST', $base.'confirm', [...$body, 'user' => $this->user->getUuid()])->getStatusCode());
        $result = $this->data($this->request('POST', $base.'confirm', $body));
        self::assertSame($result, $this->data($this->request('POST', $base.'confirm', $body)));
        $target = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']]);
        self::assertSame($ownerUuid, $target->getUser()->getUuid());
        foreach ([$first, $second] as $source) {
            self::assertSame('merged', $this->em->getRepository(Order::class)->findOneBy(['uuid' => $source['orderUuid']])->getStatus());
            self::assertSame('cancelled', $this->em->getRepository(\App\Store\Entity\StoreOrder::class)->findOneBy(['tradeOrderUuid' => $source['orderUuid']])->getOperationalStatus());
            self::assertSame($result['orderUuid'], $this->orderByUuid($source['orderUuid'])['mergedIntoOrderUuid']);
        }
        $sourceUuids = array_column([$first, $second], 'orderUuid');
        sort($sourceUuids);
        self::assertSame($sourceUuids, $this->orderByUuid($result['orderUuid'])['mergedSourceOrderUuids']);

        // Checkout may reduce the merged aggregate after additions have been consolidated.
        $this->project($result['orderUuid']);
        self::assertTrue($this->orderByUuid($result['orderUuid'])['canReplace']);
        $replacementBody = $this->replacementInput($result['orderUuid']);
        $replaced = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/replace', $replacementBody));
        self::assertSame($result['orderUuid'], $replaced['replacedOrderUuid']);
        self::assertSame('cancelled', $this->orderByUuid($result['orderUuid'])['status']);
        self::assertSame(3180, $this->orderByUuid($replaced['orderUuid'])['totalAmount']);
    }

    public function testStaffMergeRejectsInvalidOwnerAndUnprojectedSources(): void
    {
        $this->startCustomerSession(); $first = $this->place(); $second = $this->place();
        foreach ([$first, $second] as $source) $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['confirmed', $source['orderUuid']]);
        $this->em->clear();
        $path = '/counters/'.$this->counter->getUuid().'/merge/preview';
        $input = ['version' => $this->view()['version']];
        self::assertSame(422, $this->request('POST', $path, [...$input, 'user' => UUID::v4()])->getStatusCode());
        self::assertSame(422, $this->request('POST', $path, [...$input, 'user' => null])->getStatusCode());
        self::assertSame(409, $this->request('POST', $path, $input)->getStatusCode());
    }

    public function testStaffMergeDefaultsOwnerAndRejectsChangedQuote(): void
    {
        $this->startCustomerSession();
        $first = $this->place(); $this->project($first['orderUuid']);
        $second = $this->place(); $this->project($second['orderUuid']);
        foreach ([$first, $second] as $source) $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['confirmed', $source['orderUuid']]);
        $this->em->clear();
        $base = '/counters/'.$this->counter->getUuid().'/merge/';
        $input = ['version' => $this->view()['version']];
        $quote = $this->data($this->request('POST', $base.'preview', $input));
        self::assertSame($this->user->getUuid(), $quote['targetUserUuid']);
        $this->em->getConnection()->executeStatement('UPDATE trade_specification SET price = 2000 WHERE uuid = ?', [$this->spec->getUuid()]);
        $body = [...$input, 'quoteToken' => $quote['quoteToken'], 'commandUuid' => UUID::v4()];
        self::assertSame(409, $this->request('POST', $base.'confirm', $body)->getStatusCode());
        $quote = $this->data($this->request('POST', $base.'preview', $input));
        $result = $this->data($this->request('POST', $base.'confirm', [...$body, 'quoteToken' => $quote['quoteToken']]));
        self::assertSame($this->user->getUuid(), $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']])->getUser()->getUuid());
    }

    public function testSessionCanEndAfterOrdersAreCompleted(): void
    {
        $this->startCustomerSession();
        $result = $this->place();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['completed', $result['orderUuid']]);
        $this->em->clear();
        $view = $this->view();
        self::assertTrue($view['canEnd']);
        self::assertSame(0, $view['openAmount']);
        $body = ['commandUuid' => UUID::v4(), 'version' => $view['version']];
        $path = '/counters/'.$this->counter->getUuid().'/session/end';
        $ended = $this->data($this->request('POST', $path, $body));
        self::assertSame($ended, $this->data($this->request('POST', $path, $body)));
        self::assertNull($this->view()['session']);
    }

    public function testActiveSessionKeepsYesterdayOrderVisibleAndOpen(): void
    {
        $orderUuid = $this->startCustomerSession(false);
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'UPDATE trade_order SET created_at = ? WHERE uuid = ?',
            [$yesterday, $orderUuid],
        );
        $this->em->clear();

        $view = $this->view();
        self::assertCount(1, $view['orders']);
        self::assertSame($orderUuid, $view['orders'][0]['uuid']);
        self::assertSame(1590, $view['openAmount']);
        self::assertSame(1, $view['openOrderCount']);
    }

    public function testStaffMergeConfirmationCombinesCustomerOwnedOrdersWithoutADiningMode(): void
    {
        if (getenv('INVENTORY_ENABLED') === '1') {
            self::markTestSkipped('Customer-owned fixture orders have no inventory reservation.');
        }
        $customers = [];
        foreach (['first-customer', 'second-customer'] as $name) {
            $customer = (new User())->setUsername($name)->setEmail($name.'@example.test');
            $this->em->persist($customer);
            $customers[] = $customer;
        }
        $this->em->flush();
        $sources = [$this->startCustomerSession(false, $customers[0]), $this->startCustomerSession(false, $customers[1])];
        foreach ($sources as $uuid) {
            $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['confirmed', $uuid]);
            $this->project($uuid);
        }
        $this->em->clear();

        $base = '/counters/'.$this->counter->getUuid().'/merge/';
        $input = ['version' => $this->view()['version']];
        $quote = $this->data($this->request('POST', $base.'preview', $input));
        $merged = $this->data($this->request('POST', $base.'confirm', [...$input,
            'quoteToken' => $quote['quoteToken'], 'commandUuid' => UUID::v4()]));
        $view = $this->view();
        self::assertNull($view['session']['diningMode']);
        $byUuid = array_column($view['orders'], null, 'uuid');
        foreach ($sources as $uuid) {
            self::assertSame('merged', $byUuid[$uuid]['status']);
        }
        self::assertSame('confirmed', $byUuid[$merged['orderUuid']]['status']);
        self::assertSame(3180, $byUuid[$merged['orderUuid']]['totalAmount']);
    }

    public function testDiningModeIsSnapshottedAtFirstOrderAndLockedForTheSession(): void
    {
        $modeUuid = UUID::v4();
        $otherModeUuid = UUID::v4();
        $guestRuleUuid = UUID::v4();
        $categoryRuleUuid = UUID::v4();
        $this->store->setSettings([
            'diningModes' => [
                'version' => 1,
                'modes' => [[
                    'uuid' => $modeUuid,
                    'name' => 'Hotpot',
                    'enabled' => true,
                    'priority' => 10,
                    'switchButtonLabel' => 'Hotpot',
                    'availability' => ['allDay' => true, 'daysOfWeek' => [1, 2, 3, 4, 5, 6, 7], 'timeWindows' => []],
                    'catalog' => ['categoryUuids' => [], 'productUuids' => []],
                    'rules' => [],
                    'checkoutRules' => [
                        ['uuid' => $guestRuleUuid, 'name' => 'Confirm guests', 'title' => 'Please confirm the guest count', 'type' => 'confirm_participants', 'required' => true, 'categoryUuid' => ''],
                        ['uuid' => $categoryRuleUuid, 'name' => 'Offer drinks', 'title' => 'Does the table need drinks?', 'type' => 'suggest_category', 'required' => false, 'categoryUuid' => UUID::v4()],
                    ],
                ], [
                    'uuid' => $otherModeUuid,
                    'name' => 'Breakfast',
                    'enabled' => true,
                    'priority' => 5,
                    'availability' => ['allDay' => true, 'daysOfWeek' => [1, 2, 3, 4, 5, 6, 7], 'timeWindows' => []],
                    'catalog' => ['categoryUuids' => [], 'productUuids' => []],
                    'rules' => [],
                    'checkoutRules' => [],
                ]],
            ],
        ]);
        $this->em->flush();

        $initial = $this->view();
        self::assertNull($initial['session']);
        $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/session/open', [
            'version' => $initial['version'], 'commandUuid' => UUID::v4(),
        ]));

        $input = [...$this->quoteInput(), 'diningModeUuid' => $modeUuid];
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $input));
        $created = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', [
            ...$input, 'commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken'],
        ]));
        self::assertSame($this->counter->getUuid(), $created['counterUuid']);

        $bound = $this->view()['session'];
        self::assertSame($modeUuid, $bound['diningModeUuid']);
        self::assertSame('Hotpot', $bound['diningMode']['name']);
        self::assertSame([$guestRuleUuid, $categoryRuleUuid], array_column($bound['diningMode']['checkoutRules'], 'uuid'));
        self::assertSame(['Please confirm the guest count', 'Does the table need drinks?'], array_column($bound['diningMode']['checkoutRules'], 'title'));
        self::assertSame([true, false], array_column($bound['diningMode']['checkoutRules'], 'required'));

        // Configuration changes only affect later sessions; the active table keeps its order-time snapshot.
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        $settings = $store->getSettings();
        $settings['diningModes']['modes'][0]['name'] = 'Changed after ordering';
        $settings['diningModes']['modes'][0]['checkoutRules'] = [];
        $store->setSettings($settings);
        $this->em->flush();
        $this->em->clear();
        $unchanged = $this->view()['session'];
        self::assertSame('Hotpot', $unchanged['diningMode']['name']);
        self::assertCount(2, $unchanged['diningMode']['checkoutRules']);

        self::assertSame(409, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', [
            ...$this->quoteInput(), 'diningModeUuid' => $otherModeUuid,
        ])->getStatusCode());

        // Additions inherit the bound snapshot, including when a compatible client omits the UUID.
        $addition = $this->quoteInput();
        $additionQuote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $addition));
        $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', [
            ...$addition, 'commandUuid' => UUID::v4(), 'quoteToken' => $additionQuote['quoteToken'],
        ]));
        self::assertCount(2, $this->view()['orders']);
    }

    public function testDiningModeMigrationAddsNullableSnapshotColumns(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261101090000.php';
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE counter_session (id INTEGER PRIMARY KEY, uuid VARCHAR(36) NOT NULL)');
        $connection->insert('counter_session', ['id' => 1, 'uuid' => UUID::v4()]);
        $migration = new \NsUltimate\Business\Counter\Migrations\Version20261101090000($connection, new \Psr\Log\NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $row = $connection->fetchAssociative('SELECT dining_mode_uuid, dining_mode_snapshot FROM counter_session WHERE id = 1');
        self::assertNull($row['dining_mode_uuid']);
        self::assertNull($row['dining_mode_snapshot']);

        $down = new \NsUltimate\Business\Counter\Migrations\Version20261101090000($connection, new \Psr\Log\NullLogger());
        // Doctrine's SQLite schema manager cannot reverse-map the portable JSON
        // column type used by MySQL, even though SQLite accepts the DDL itself.
        $down->down(new \Doctrine\DBAL\Schema\Schema());
        foreach ($down->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM counter_session'));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM pragma_table_info('counter_session') WHERE name = 'dining_mode_snapshot'"));

        $mysql = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.32']);
        $ddl = new \NsUltimate\Business\Counter\Migrations\Version20261101090000($mysql, new \Psr\Log\NullLogger());
        $ddl->up(new \Doctrine\DBAL\Schema\Schema());
        self::assertFalse($ddl->isTransactional());
        $sql = implode(' ', array_map(static fn($query): string => $query->getStatement(), $ddl->getSql()));
        self::assertStringContainsString('dining_mode_uuid', $sql);
        self::assertStringContainsString('dining_mode_snapshot', $sql);
        self::assertFalse($mysql->isConnected());
    }

    public function testOrdersAreAnonymousAndPreserveTrustedStorePolicyAndStaffProvenance(): void
    {
        $this->startCustomerSession(); $result = $this->place();
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']]);
        self::assertNull($order->getUser());
        self::assertSame('pending', $order->getStatus());
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $order->getUuid(), 'topic' => 'trade.order.created.v1']);
        self::assertTrue($event->getPayload()['store']['requireAcceptance']);
        self::assertSame('staff', $event->getPayload()['store']['channel']);
        self::assertNull($event->getPayload()['customerUserUuid']);
        $receipt = $this->em->getRepository(StaffOperation::class)->findOneBy(['commandUuid' => $result['_input']['commandUuid']]);
        self::assertSame($this->user->getUuid(), $receipt->summary()['actorUuid']);
        self::assertSame($this->store->getUuid(), $event->getPayload()['store']['uuid']);
    }

    public function testQuoteChangesRejectWithoutNewOrdersOrReceipts(): void
    {
        $this->startCustomerSession(); $first = $this->place(); $this->project($first['orderUuid']);
        $body = $this->quoteInput();
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $body));
        $this->em->getConnection()->executeStatement('UPDATE trade_specification SET price = 2000 WHERE uuid = ?', [$this->spec->getUuid()]);
        $body += ['commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken']];
        self::assertSame(409, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', $body)->getStatusCode());
        self::assertSame(2, $this->em->getRepository(Order::class)->count([]));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));
        self::assertSame('pending', $this->orderByUuid($first['orderUuid'])['status']);
    }

    public function testSessionVersionConflictsAndUnknownFieldsFailClosed(): void
    {
        $this->startCustomerSession(); $view = $this->view();
        $path = '/counters/'.$this->counter->getUuid().'/session/update';
        $body = ['commandUuid' => UUID::v4(), 'version' => $view['version'], 'participantCount' => 5, 'notes' => 'Birthday'];
        $this->data($this->request('POST', $path, $body));
        self::assertSame(409, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4()])->getStatusCode());
        self::assertSame(409, $this->request('POST', $path, [...$body, 'notes' => 'Changed retry'])->getStatusCode());
        $view = $this->view();
        self::assertSame(5, $view['session']['participantCount']);
        self::assertSame(422, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'version' => $view['version'], 'storeUuid' => UUID::v4()])->getStatusCode());
        foreach ([0, 101, '2'] as $count) self::assertSame(422, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'version' => $view['version'], 'participantCount' => $count])->getStatusCode());
    }

    public function testQuoteBindsNotesQuantityPolicyAndSessionVersion(): void
    {
        $this->startCustomerSession();
        $input = $this->quoteInput();
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $input));
        $body = [...$input, 'quoteToken' => $quote['quoteToken'], 'commandUuid' => UUID::v4()];
        $path = '/counters/'.$this->counter->getUuid().'/orders/create';
        self::assertSame(409, $this->request('POST', $path, [...$body, 'notes' => 'Changed notes'])->getStatusCode());
        self::assertSame(409, $this->request('POST', $path, [...$body, 'items' => [['specificationUuid' => $this->spec->getUuid(), 'quantity' => 1]]])->getStatusCode());
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        $store->setSettings(['order' => ['requireAcceptance' => false]]); $this->em->flush();
        self::assertSame(409, $this->request('POST', $path, $body)->getStatusCode());
        $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/session/update', ['commandUuid' => UUID::v4(), 'version' => $input['version'], 'participantCount' => 4, 'notes' => 'New party']));
        self::assertSame(409, $this->request('POST', $path, $body)->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Order::class)->count([]));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testMalformedCartCannotCreateOrders(): void
    {
        $this->startCustomerSession(); $input = $this->quoteInput();
        foreach ([[], array_fill(0, 31, $input['items'][0]), [$input['items'][0], $input['items'][0]], [['specificationUuid' => $this->spec->getUuid(), 'quantity' => 0]], [['specificationUuid' => $this->spec->getUuid(), 'quantity' => '2']], [['specificationUuid' => UUID::v4(), 'quantity' => 1]], [['specificationUuid' => $this->spec->getUuid(), 'quantity' => 1, 'price' => 1]]] as $items) {
            self::assertSame(422, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', [...$input, 'items' => $items])->getStatusCode());
        }
        self::assertSame(1, $this->em->getRepository(Order::class)->count([]));
    }

    public function testAuthorizationScopeRedactionAndBoundedReads(): void
    {
        self::assertSame(401, $this->request('GET', '/counters', null, false)->getStatusCode());
        $other = (new Counter())->setStoreUuid(UUID::v4())->setCode('secret')->setName('Secret');
        $this->em->persist($other); $this->em->flush();
        self::assertSame(404, $this->request('GET', '/counters/'.$other->getUuid())->getStatusCode());
        self::assertSame(1, $this->data($this->request('GET', '/counters'))['total']);
        self::assertSame(422, $this->request('GET', '/counters?page=0')->getStatusCode());
        self::assertSame(1, $this->data($this->request('GET', '/catalog'))['total']);
        self::assertStringContainsString('no-store', $this->request('GET', '/counters')->headers->get('Cache-Control'));
        $this->startCustomerSession();
        $detail = $this->view();
        self::assertTrue($detail['ordersVisible']);
        self::assertArrayHasKey('openAmount', $detail);
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        $sessionRead = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_session:read']);
        $role->removePermission($sessionRead); $role->touch(); $this->em->flush();
        $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        $orderReadOnly = $this->view();
        self::assertTrue($orderReadOnly['ordersVisible']);
        self::assertCount(1, $orderReadOnly['orders']);
        self::assertSame($detail['version'], $orderReadOnly['version']);
        // Session reads alone must not disclose order rows or amounts.
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        $read = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_order:read']);
        $sessionRead = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_session:read']);
        $role->removePermission($read); $role->addPermission($sessionRead); $role->touch(); $this->em->flush();
        $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        $redacted = $this->view();
        self::assertFalse($redacted['ordersVisible']);
        self::assertArrayNotHasKey('orders', $redacted);
        self::assertArrayNotHasKey('openAmount', $redacted);
        self::assertSame($detail['version'], $redacted['version']);
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('GET', '/counters')->getStatusCode());
    }

    public function testOrderCreateCanOpenAnEmptyCounterWithoutSessionReadPermission(): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        foreach (['store:counter_session:read', 'store:counter_session:update'] as $code) {
            $role->removePermission($this->em->getRepository(Permission::class)->findOneBy(['code' => $code]));
        }
        $role->touch(); $this->em->flush(); $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());

        $view = $this->view();
        self::assertNull($view['session']);
        $result = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/session/open', [
            'version' => $view['version'], 'commandUuid' => UUID::v4(),
        ]));
        self::assertSame($this->counter->getUuid(), $result['counterUuid']);
        self::assertNotNull($this->view()['session']);
    }

    public function testSoldOutAndCrossStoreSpecificationsAreNotOrderable(): void
    {
        $this->startCustomerSession();
        $other = new Store('other-counter-session', 'Other');
        $otherSpec = (new Specification())->setProduct((new Product($other))->setName('Secret'))->setName('Other')->setPrice(100);
        $this->em->persist($other); $this->em->persist($otherSpec->getProduct()); $this->em->persist($otherSpec); $this->em->flush();
        $input = $this->quoteInput();
        self::assertSame(422, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', [...$input, 'items' => [['specificationUuid' => $otherSpec->getUuid(), 'quantity' => 1]]])->getStatusCode());
        $product = $this->em->getRepository(Product::class)->find($this->spec->getProduct()->getId());
        $product->setIsSoldout(true); $this->em->flush();
        self::assertFalse($this->data($this->request('GET', '/catalog'))['items'][0]['available']);
        self::assertSame(422, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $input)->getStatusCode());
    }

    public function testCoreCatalogProductsCanBeQuotedCreatedAndReplaced(): void
    {
        $this->startCustomerSession();
        // A kernel request may detach the fixture; create against the managed store.
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        $core = (new \App\Store\Entity\Product($store))->setName('Core service item');
        $coreSpec = (new Specification())->setProduct($core)->setName('Standard')->setPrice(900);
        $this->em->persist($core); $this->em->persist($coreSpec); $this->em->flush();
        $catalog = $this->data($this->request('GET', '/catalog?q=Core'))['items'];
        self::assertCount(1, $catalog);
        self::assertTrue($catalog[0]['available']);
        $input = ['version' => $this->view()['version'], 'items' => [['specificationUuid' => $coreSpec->getUuid(), 'quantity' => 2]], 'notes' => 'Core product'];
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $input));
        self::assertSame(1800, $quote['totalAmount']);
        $created = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', $input + ['commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken']]));
        $this->project($created['orderUuid']);
        self::assertTrue($this->orderByUuid($created['orderUuid'])['canReplace']);
        $replacement = ['version' => $this->view()['version'], 'items' => [['specificationUuid' => $coreSpec->getUuid(), 'quantity' => 1]], 'notes' => 'Core product', 'replaceOrderUuid' => $created['orderUuid']];
        $replaceQuote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $replacement));
        $replaced = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/replace', $replacement + ['commandUuid' => UUID::v4(), 'quoteToken' => $replaceQuote['quoteToken'], 'reason' => 'Single portion']));
        self::assertSame($created['orderUuid'], $replaced['replacedOrderUuid']);
        self::assertSame(900, $this->orderByUuid($replaced['orderUuid'])['totalAmount']);
    }

    public function testInventoryRequiredPolicyIsPreserved(): void
    {
        $this->store->setSettings(['order' => ['requireAcceptance' => true, 'requireInventory' => true]]);
        $this->em->flush();
        $this->startCustomerSession(); $result = $this->place(); $this->project($result['orderUuid']);
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $result['orderUuid'], 'topic' => 'trade.order.created.v1']);
        self::assertTrue($event->getPayload()['store']['requireInventory']);
        self::assertFalse($this->orderByUuid($result['orderUuid'])['canReplace']);
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']]);
        $metadata = $order->getMetadata();
        $metadata['_store']['requireInventory'] = false;
        $order->setMetadata($metadata); $this->em->flush();
        self::assertFalse($this->orderByUuid($result['orderUuid'])['canReplace']);
    }

    public function testCurrencyMismatchDoesNotProduceAMisleadingSessionTotal(): void
    {
        $this->startCustomerSession(); $result = $this->place();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET currency = ? WHERE uuid = ?', ['USD', $result['orderUuid']]);
        $this->em->clear();
        self::assertSame(409, $this->request('GET', '/counters/'.$this->counter->getUuid())->getStatusCode());
    }

    public function testPlacementUsesOneAnonymousCalculationAndTheCheckedTotal(): void
    {
        $probe = new class implements \App\Trade\Service\Pricing\PriceCalculatorInterface {
            public int $calls = 0;
            public static function getPriority(): int { return 1000; }
            public function calculate(\App\Trade\Service\Pricing\PriceCalculationContext $context): void
            {
                ++$this->calls;
                if ($context->user !== null) throw new \LogicException('Employee must never become the pricing customer.');
                $context->totalAmount += 100;
            }
        };
        $pricing = new StaffOrderPricing($this->em, [
            self::getContainer()->get(\App\Trade\Service\Pricing\BasePriceCalculator::class),
            self::getContainer()->get(\App\Trade\Service\Pricing\QuantityCalculator::class),
            self::getContainer()->get(\App\Trade\Service\Pricing\TotalAggregator::class), $probe,
        ]);
        self::getContainer()->set(StaffOrderPricing::class, $pricing);
        $this->startCustomerSession(); $body = $this->quoteInput();
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $body));
        self::assertSame(1, $probe->calls);
        self::assertSame(3280, $quote['totalAmount']);
        $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', [...$body, 'commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken']]));
        self::assertSame(2, $probe->calls);
        self::assertSame(3280, $this->employeeOrder()['totalAmount']);
    }

    public function testCounterSessionMigrationQueuesPortableDdlAndPreservesHistoricalSessions(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261031100000.php';
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('CREATE TABLE counter_session (id INTEGER PRIMARY KEY, uuid VARCHAR(36) NOT NULL)');
        $connection->insert('counter_session', ['id' => 1, 'uuid' => UUID::v4()]);
        $migration = new \NsUltimate\Business\Counter\Migrations\Version20261031100000($connection, new \Psr\Log\NullLogger());
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration->up($schema);
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        self::assertSame(0, $connection->fetchOne('SELECT revision FROM counter_session WHERE id = 1'));
        self::assertNull($connection->fetchOne('SELECT participant_count FROM counter_session WHERE id = 1'));
        $down = new \NsUltimate\Business\Counter\Migrations\Version20261031100000($connection, new \Psr\Log\NullLogger());
        $down->down($connection->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM counter_session'));
        $mysql = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.32']);
        $ddl = new \NsUltimate\Business\Counter\Migrations\Version20261031100000($mysql, new \Psr\Log\NullLogger());
        $ddl->up(new \Doctrine\DBAL\Schema\Schema());
        self::assertFalse($ddl->isTransactional());
        self::assertStringContainsString('ALTER TABLE counter_session', implode(' ', array_map(static fn($sql): string => $sql->getStatement(), $ddl->getSql())));
        self::assertFalse($mysql->isConnected());
    }

    public function testAuthorizedStaffCanOpenAnEmptyCounterAndPlaceTheFirstOrder(): void
    {
        $path = '/counters/'.$this->counter->getUuid().'/session/open';
        $initial = $this->view();
        self::assertNull($initial['session']);
        $body = ['commandUuid' => UUID::v4(), 'version' => $initial['version']];
        $opened = $this->data($this->request('POST', $path, $body));
        self::assertSame($this->counter->getUuid(), $opened['counterUuid']);
        self::assertSame($opened, $this->data($this->request('POST', $path, $body)));
        self::assertSame(409, $this->request('POST', $path, ['commandUuid' => UUID::v4(), 'version' => $this->view()['version']])->getStatusCode());
        self::assertSame(422, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'notes' => 'not accepted'])->getStatusCode());
        self::assertNotNull($this->view()['session']);
        $this->place();
        self::assertCount(1, $this->view()['orders']);
        self::assertSame(2, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testEligibleOrderCancellationIsAuditedVersionedAndIdempotent(): void
    {
        $uuid = $this->startCustomerSession(false); $this->project($uuid);
        $view = $this->view();
        self::assertTrue($this->orderByUuid($uuid)['canCancel']);
        $body = ['commandUuid' => UUID::v4(), 'version' => $view['version'], 'orderUuid' => $uuid, 'reason' => 'Guest left'];
        $path = '/counters/'.$this->counter->getUuid().'/orders/cancel';
        $result = $this->data($this->request('POST', $path, $body));
        self::assertSame(['counterUuid' => $this->counter->getUuid(), 'sessionUuid' => $view['session']['uuid'], 'orderUuid' => $uuid, 'status' => 'cancelled'], $result);
        self::assertSame($result, $this->data($this->request('POST', $path, $body)));
        self::assertSame(409, $this->request('POST', $path, [...$body, 'reason' => 'Changed retry'])->getStatusCode());
        self::assertSame(422, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'version' => $this->view()['version'], 'reason' => ' '])->getStatusCode());
        self::assertSame(422, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'version' => $this->view()['version'], 'order' => $uuid])->getStatusCode());
        self::assertSame(409, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4(), 'version' => $this->view()['version'], 'orderUuid' => UUID::v4()])->getStatusCode());
        $row = $this->orderByUuid($uuid);
        self::assertSame('cancelled', $row['status']);
        self::assertFalse($row['canCancel']);
        self::assertTrue($this->view()['canEnd']);
        $receipt = $this->em->getConnection()->fetchAssociative('SELECT action, actor_uuid, before_state, result FROM store_staff_operation WHERE command_uuid = ?', [$body['commandUuid']]);
        $before = json_decode($receipt['before_state'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('counter_order.cancel.'.$this->counter->getUuid(), $receipt['action']);
        self::assertSame($this->user->getUuid(), $receipt['actor_uuid']);
        self::assertSame($uuid, $before['order']['uuid']);
        self::assertSame('Guest left', $before['reason']);
        self::assertSame($result, json_decode($receipt['result'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('cancelled', $this->em->getRepository(Order::class)->findOneBy(['uuid' => $uuid])->getStatus());
    }

    public function testCancellationGrantIsIndependentFromOrderingAndModification(): void
    {
        $uuid = $this->startCustomerSession(false); $this->project($uuid);
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        foreach (['store:counter_order:create', 'store:counter_order:replace'] as $code) {
            $role->removePermission($this->em->getRepository(Permission::class)->findOneBy(['code' => $code]));
        }
        $role->touch(); $this->em->flush();
        $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->em->getRepository(User::class)->findOneBy(['uuid' => $this->user->getUuid()]));
        $path = '/counters/'.$this->counter->getUuid().'/orders/cancel';
        $body = ['commandUuid' => UUID::v4(), 'version' => $this->view()['version'], 'orderUuid' => $uuid, 'reason' => 'Guest left'];
        self::assertSame(200, $this->request('POST', $path, $body)->getStatusCode());
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        $role->removePermission($this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_order:cancel']));
        foreach (['store:counter_order:create', 'store:counter_order:replace'] as $code) {
            $role->addPermission($this->em->getRepository(Permission::class)->findOneBy(['code' => $code]));
        }
        $role->touch(); $this->em->flush();
        $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->em->getRepository(User::class)->findOneBy(['uuid' => $this->user->getUuid()]));
        self::assertSame(403, $this->request('POST', $path, [...$body, 'commandUuid' => UUID::v4()])->getStatusCode());
    }

    public function testCustomerOrdersCanBeReplacedWithOriginalCustomerAndAuditedLinks(): void
    {
        $customer = (new User())->setUsername('counter-customer')->setEmail('customer@example.test');
        $this->em->persist($customer); $this->em->flush();
        $uuid = $this->startCustomerSession(false, $customer);
        $this->project($uuid);
        self::assertTrue($this->orderByUuid($uuid)['canReplace']);
        $body = $this->replacementInput($uuid);
        $path = '/counters/'.$this->counter->getUuid().'/orders/replace';
        $result = $this->data($this->request('POST', $path, $body));
        self::assertSame($uuid, $result['replacedOrderUuid']);
        self::assertSame($result, $this->data($this->request('POST', $path, $body)));
        self::assertSame(2, $this->em->getRepository(Order::class)->count([]));
        self::assertSame('cancelled', $this->orderByUuid($uuid)['status']);
        $new = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $result['orderUuid']]);
        self::assertSame($customer->getUuid(), $new->getUser()->getUuid());
        self::assertSame(3180, $new->getTotalAmount());
        $receipt = $this->em->getConnection()->fetchAssociative('SELECT action, actor_uuid, before_state, result FROM store_staff_operation WHERE command_uuid = ?', [$body['commandUuid']]);
        $before = json_decode($receipt['before_state'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('counter_order.replace.'.$this->counter->getUuid(), $receipt['action']);
        self::assertSame($this->user->getUuid(), $receipt['actor_uuid']);
        self::assertSame($uuid, $before['order']['uuid']);
        self::assertSame('Correct quantity', $before['reason']);
        self::assertSame($result, json_decode($receipt['result'], true, flags: JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('customerUuid', $before['order']);
        self::assertSame(409, $this->request('POST', $path, [...$body, 'reason' => 'Changed retry'])->getStatusCode());
    }

    public function testReplacementPricingUsesOriginalCustomerRatherThanEmployee(): void
    {
        $customer = (new User())->setUsername('priced-customer')->setEmail('priced-customer@example.test');
        $this->em->persist($customer); $this->em->flush();
        $probe = new class implements \App\Trade\Service\Pricing\PriceCalculatorInterface {
            public array $customers = [];
            public static function getPriority(): int { return 1000; }
            public function calculate(\App\Trade\Service\Pricing\PriceCalculationContext $context): void
            {
                $this->customers[] = $context->user?->getUuid();
                if ($context->user !== null) $context->totalAmount -= 100;
            }
        };
        self::getContainer()->set(StaffOrderPricing::class, new StaffOrderPricing($this->em, [
            self::getContainer()->get(\App\Trade\Service\Pricing\BasePriceCalculator::class),
            self::getContainer()->get(\App\Trade\Service\Pricing\QuantityCalculator::class),
            self::getContainer()->get(\App\Trade\Service\Pricing\TotalAggregator::class), $probe,
        ]));
        $uuid = $this->startCustomerSession(false, $customer); $this->project($uuid);
        $body = $this->replacementInput($uuid);
        $result = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/replace', $body));
        self::assertSame([$customer->getUuid(), $customer->getUuid()], $probe->customers);
        self::assertSame(3080, $this->orderByUuid($result['orderUuid'])['totalAmount']);
    }

    public function testReplacementGrantIsIndependentAndCheckedOnQuotesAndReplay(): void
    {
        $uuid = $this->startCustomerSession(false); $this->project($uuid);
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        $create = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_order:create']);
        $role->removePermission($create); $role->touch(); $this->em->flush();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $this->quoteInput())->getStatusCode());
        self::assertSame(200, $this->request('GET', '/catalog')->getStatusCode());
        $body = $this->replacementInput($uuid);
        $path = '/counters/'.$this->counter->getUuid().'/orders/replace';
        $this->data($this->request('POST', $path, $body));
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'counter-session-test']);
        $create = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_order:create']);
        $replace = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'store:counter_order:replace']);
        $role->removePermission($replace); $role->addPermission($create); $role->touch(); $this->em->flush();
        $this->em->clear();
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->em->getRepository(User::class)->findOneBy(['uuid' => $this->user->getUuid()]));
        self::assertSame(403, $this->request('POST', $path, $body)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', [...$this->quoteInput(), 'replaceOrderUuid' => $uuid])->getStatusCode());
    }

    public function testReplacementRejectsProcessingPaymentInventoryAndForeignTargets(): void
    {
        $uuid = $this->startCustomerSession(false);
        self::assertFalse($this->orderByUuid($uuid)['canReplace']);
        self::assertSame('projection_pending', $this->orderByUuid($uuid)['changeBlockedReason']);
        $this->project($uuid);
        $path = '/counters/'.$this->counter->getUuid().'/orders/quote';
        foreach ([['trade_order', 'invoice_id', UUID::v4()], ['trade_order', 'payment_status', 'paid'], ['trade_order', 'status', 'paid'], ['store_order', 'operational_status', 'accepted'], ['store_order', 'reservation_id', UUID::v4()], ['trade_order_store_lifecycle', 'acceptance_status', 'accepted']] as [$table, $column, $value]) {
            $identity = $table === 'trade_order' ? 'uuid' : 'trade_order_uuid';
            $old = $this->em->getConnection()->fetchOne("SELECT $column FROM $table WHERE $identity = ?", [$uuid]);
            $this->em->getConnection()->executeStatement("UPDATE $table SET $column = ? WHERE $identity = ?", [$value, $uuid]); $this->em->clear();
            self::assertFalse($this->orderByUuid($uuid)['canReplace']);
            self::assertFalse($this->orderByUuid($uuid)['canCancel']);
            $expectedReason = match ($column) {
                'invoice_id', 'payment_status' => 'payment_started',
                'operational_status', 'acceptance_status' => 'order_processed',
                'reservation_id' => 'inventory_reserved',
                default => 'order_ineligible',
            };
            self::assertSame($expectedReason, $this->orderByUuid($uuid)['changeBlockedReason']);
            self::assertSame(409, $this->request('POST', $path, [...$this->quoteInput(), 'replaceOrderUuid' => $uuid])->getStatusCode());
            $this->em->getConnection()->executeStatement("UPDATE $table SET $column = ? WHERE $identity = ?", [$old, $uuid]); $this->em->clear();
        }
        self::assertSame(409, $this->request('POST', $path, [...$this->quoteInput(), 'replaceOrderUuid' => UUID::v4()])->getStatusCode());
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $uuid]);
        $order->setMetadata(['_store' => ['uuid' => UUID::v4()]]); $this->em->flush();
        self::assertFalse($this->orderByUuid($uuid)['canReplace']);
        self::assertFalse($this->orderByUuid($uuid)['canCancel']);
        self::assertSame(0, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testReplacementConflictsAndFailuresRollBackAllChanges(): void
    {
        $uuid = $this->startCustomerSession(false); $this->project($uuid);
        $body = $this->replacementInput($uuid);
        $path = '/counters/'.$this->counter->getUuid().'/orders/replace';
        self::assertSame(422, $this->request('POST', $path, [...$body, 'reason' => ' '])->getStatusCode());
        self::assertSame(422, $this->request('POST', $path, array_diff_key($body, ['replaceOrderUuid' => true]))->getStatusCode());
        $this->em->getConnection()->executeStatement('UPDATE trade_specification SET price = 2000 WHERE uuid = ?', [$this->spec->getUuid()]);
        self::assertSame(409, $this->request('POST', $path, $body)->getStatusCode());
        $this->em->getConnection()->executeStatement('UPDATE trade_specification SET price = 1590 WHERE uuid = ?', [$this->spec->getUuid()]); $this->em->clear();
        $body = $this->replacementInput($uuid);
        $container = self::getContainer();
        $storeOrders = $this->createMock(StoreOrderServiceInterface::class);
        $storeOrders->expects(self::once())->method('cancel')->willThrowException(new \LogicException('Simulated cancellation failure.'));
        $service = new \NsUltimate\Business\Counter\Service\CounterSessionOperations($this->em,
            $container->get(\NsUltimate\Business\Counter\Service\CounterSessionService::class),
            $container->get(\NsUltimate\Business\Counter\Service\DiningModeSnapshotResolver::class),
            $container->get(StaffOrderPricing::class),
            new StaffOrderService($container->get(OrderServiceInterface::class), $storeOrders, $container->get('state_machine.order')),
            $storeOrders,
            $container->get('state_machine.order'));
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        $events = $this->em->getRepository(TradeOutboxMessage::class)->count([]);
        try {
            $container->get(\NsUltimate\Business\Store\Service\StaffCommandService::class)->run($store, $this->user->getUuid(), 'counter_order.replace.'.$this->counter->getUuid(), $body,
                fn(): array => $service->submit($store, $this->counter->getUuid(), $body, true));
            self::fail('A failed replacement must roll back.');
        } catch (\LogicException $error) { self::assertSame('Simulated cancellation failure.', $error->getMessage()); }
        self::assertSame(1, $this->em->getRepository(Order::class)->count([]));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter_order'));
        self::assertSame($events, $this->em->getRepository(TradeOutboxMessage::class)->count([]));
        self::assertSame(0, $this->em->getRepository(StaffOperation::class)->count([]));
        self::assertSame('pending', $this->orderByUuid($uuid)['status']);
    }

    public function testReplacementCannotCrossCountersOrChangeMultiCounterOrders(): void
    {
        $uuid = $this->startCustomerSession(false); $this->project($uuid);
        $otherCounter = (new Counter())->setStoreUuid($this->store->getUuid())->setCode('B01')->setName('Other table');
        $this->em->persist($otherCounter); $this->em->flush();
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        $orders = self::getContainer()->get(OrderServiceInterface::class);
        $otherOrder = $orders->createOrder([], null, 0, $store->getCurrency(), null, ['counterUuid' => $otherCounter->getUuid()], new \App\Trade\DTO\StoreContext($store->getUuid(), $store->getCode(), $store->getName(), 'customer', $store->getCurrency(), true));
        $path = '/counters/'.$this->counter->getUuid().'/orders/quote';
        self::assertSame(409, $this->request('POST', $path, [...$this->quoteInput(), 'replaceOrderUuid' => $otherOrder->getUuid()])->getStatusCode());
        $otherCounter = $this->em->getRepository(Counter::class)->findOneBy(['uuid' => $otherCounter->getUuid()]);
        $binding = (new \NsUltimate\Business\Counter\Entity\OrderCounter())->setCounter($otherCounter)->setStoreUuid($store->getUuid())->setTradeOrderUuid($uuid);
        $this->em->wrapInTransaction(fn() => $this->em->persist($binding));
        self::assertFalse($this->orderByUuid($uuid)['canReplace']);
        self::assertFalse($this->orderByUuid($uuid)['canCancel']);
        self::assertSame(409, $this->request('POST', $path, [...$this->quoteInput(), 'replaceOrderUuid' => $uuid])->getStatusCode());
    }

    public function testCounterSeedKeepsConfigurationAndCapabilityGrantsSeparate(): void
    {
        $command = self::getContainer()->get(\NsUltimate\Business\Counter\Command\SeedCounterAuthorizationCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $count = $this->em->getRepository(Permission::class)->count([]);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($count, $this->em->getRepository(Permission::class)->count([]));
        $manager = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_counter_manager']);
        self::assertSame(Role::SCOPE_STORE, $manager->getScopeType());
        self::assertTrue($manager->hasPermission('store:counter:update'));
        // Counter configuration must not silently grant session or order authority.
        foreach (['store:counter_session:read', 'store:counter_session:update', 'store:counter_session:end', 'store:counter_order:read', 'store:counter_order:create', 'store:counter_order:replace', 'store:counter_order:cancel'] as $code) {
            self::assertFalse($manager->hasPermission($code), $code);
        }
        $operator = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_counter_session_operator']);
        self::assertTrue($operator->hasPermission('store:counter_session:end'));
        self::assertTrue($operator->hasPermission('store:counter_order:create'));
        self::assertFalse($operator->hasPermission('store:counter_order:replace'));
        self::assertFalse($operator->hasPermission('store:counter_order:cancel'));
        self::assertFalse($operator->hasPermission('store:counter:update'));
        $editor = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_counter_order_editor']);
        self::assertTrue($editor->hasPermission('store:counter_order:replace'));
        self::assertFalse($editor->hasPermission('store:counter_order:create'));
        self::assertFalse($editor->hasPermission('store:counter_order:cancel'));
        self::assertFalse($editor->hasPermission('store:counter_session:end'));
        $canceller = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_counter_order_canceller']);
        self::assertTrue($canceller->hasPermission('store:counter_order:cancel'));
        self::assertTrue($canceller->hasPermission('store:counter_order:read'));
        self::assertFalse($canceller->hasPermission('store:counter_order:create'));
        self::assertFalse($canceller->hasPermission('store:counter_order:replace'));
    }

    private function replacementInput(string $uuid): array
    {
        $input = [...$this->quoteInput(), 'replaceOrderUuid' => $uuid];
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $input));
        return [...$input, 'commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken'], 'reason' => 'Correct quantity'];
    }

    private function startCustomerSession(bool $close = true, ?User $customer = null): string
    {
        $orders = self::getContainer()->get(OrderServiceInterface::class);
        $actor = $customer ?? $this->user;
        // A previous kernel request may have detached the fixture; reattach before creating an order.
        if (!$this->em->contains($actor)) $actor = $this->em->getRepository(User::class)->findOneBy(['uuid' => $actor->getUuid()]);
        $calculation = $orders->calculatePrices([['specificationId' => $this->spec->getId(), 'quantity' => 1]], $this->store->getCurrency(), $this->store->getCode());
        $context = new \App\Trade\DTO\StoreContext($this->store->getUuid(), $this->store->getCode(), $this->store->getName(), 'customer', $this->store->getCurrency(), true);
        $order = $orders->createOrder($calculation->items, $actor, $calculation->totalAmount, $this->store->getCurrency(), null, ['counterUuid' => $this->counter->getUuid()], $context);
        $view = $this->view();
        self::assertNotNull($view['session']);
        self::assertContains($order->getUuid(), array_column($view['orders'], 'uuid'));
        // Keep the real customer binding while closing its order for the original staff-flow scenarios.
        if ($close) $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE uuid = ?', ['cancelled', $order->getUuid()]);
        $this->em->clear();
        return $order->getUuid();
    }

    private function orderByUuid(string $uuid): array
    {
        foreach ($this->view()['orders'] as $order) if ($order['uuid'] === $uuid) return $order;
        self::fail('Order not found in counter session.');
    }

    private function employeeOrder(): array
    {
        foreach ($this->view()['orders'] as $order) if ($order['notes'] === 'Less salt') return $order;
        self::fail('Employee order not found in counter session.');
    }

    private function view(): array { return $this->data($this->request('GET', '/counters/'.$this->counter->getUuid())); }

    private function quoteInput(): array
    {
        return ['version' => $this->view()['version'], 'items' => [['specificationUuid' => $this->spec->getUuid(), 'quantity' => 2]], 'notes' => 'Less salt'];
    }

    private function place(): array
    {
        $body = $this->quoteInput();
        $quote = $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/quote', $body));
        self::assertSame(3180, $quote['totalAmount']);
        $body += ['commandUuid' => UUID::v4(), 'quoteToken' => $quote['quoteToken']];
        return $this->data($this->request('POST', '/counters/'.$this->counter->getUuid().'/orders/create', $body)) + ['_input' => $body];
    }

    private function project(string $uuid): void
    {
        $event = $this->em->getRepository(TradeOutboxMessage::class)->findOneBy(['aggregateId' => $uuid, 'topic' => 'trade.order.created.v1']);
        self::getContainer()->get(StoreOrderServiceInterface::class)->createFromTradeOrderSnapshot($this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]), $event->getPayload());
    }

    private function request(string $method, string $path, ?array $body = null, bool $authenticated = true): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/store/'.$this->store->getUuid().'/counter-operations'.$path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', ...($authenticated ? ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token] : [])], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }
}
