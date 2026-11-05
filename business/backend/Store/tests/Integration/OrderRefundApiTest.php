<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Business\Store\DTO\OrderRefundReceipt;
use NsUltimate\Business\Store\Service\OrderRefundMetadata;
use NsUltimate\Business\Store\Service\OrderRefundService;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../../../HuifuFin/tests/Support/FakeHuifuClient.php';

final class OrderRefundApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private User $user;
    private Order $order;
    private Invoice $invoice;
    private string $token;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->store = new Store('order-refunds', 'Order refunds');
        $this->user = (new User())->setUsername('order-refunder')->setEmail('order-refunder@example.test');
        $role = new Role('refund-test', 'Refund test', Role::SCOPE_STORE);
        $permission = new Permission('store:order:refund', 'store', 'order', 'refund', 'Refund order');
        $role->addPermission($permission);
        $this->order = (new Order())->setUser($this->user)->setStatus('paid')->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($this->user)->setStatus('paid')->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setPayment('mock');
        $this->order->setInvoiceId($this->invoice->getUuid());
        foreach ([$this->store, $this->user, $role, $permission, $this->order, $this->invoice,
            new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid()),
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
    }

    public function testPartialRefundReplayConflictAndCumulativeFullRefund(): void
    {
        $first = $this->post(400, 'first');
        self::assertSame(200, $first->getStatusCode(), $first->getContent());
        $data = $this->data($first);
        self::assertSame('succeeded', $data['status']);
        self::assertSame('partial_refunded', $data['invoiceStatus']);
        self::assertSame(400, $data['refundedAmount']);
        self::assertSame('paid', $this->order->getStatus());
        self::assertSame($data['uuid'], $this->data($this->post(400, 'first'))['uuid']);
        self::assertSame(409, $this->post(300, 'first')->getStatusCode());
        // Identical amount and reason with a NEW key is a separate refund.
        self::assertSame(800, $this->data($this->post(400, 'second'))['refundedAmount']);
        self::assertSame(409, $this->post(300, 'too-much')->getStatusCode());
        $last = $this->data($this->post(200, 'last'));
        self::assertSame('refunded', $last['invoiceStatus']);
        self::assertSame('refunded', $this->em->find(Order::class, $this->order->getId())->getStatus());
        self::assertSame($last['uuid'], $this->data($this->post(200, 'last'))['uuid']);
        self::assertSame(3, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        $read = $this->request('GET', '/refunds/'.$data['uuid']);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('succeeded', $this->data($read)['status']);
        self::assertStringContainsString('no-store', $read->headers->get('Cache-Control'));
        self::assertSame(404, $this->request('GET', '/refunds/'.UUID::v4())->getStatusCode());
    }

    public function testRefundHistoryShowsConfirmedAmountsWithoutResubmitting(): void
    {
        self::assertSame([], $this->data($this->request('GET', '/refunds')));
        $first = $this->data($this->post(300, 'history-partial'));
        self::assertSame('paid', $this->order->getStatus());
        $rows = $this->data($this->request('GET', '/refunds'));
        self::assertCount(1, $rows);
        self::assertSame($first['uuid'], $rows[0]['uuid']);
        self::assertSame('succeeded', $rows[0]['status']);
        self::assertSame('partial_refunded', $rows[0]['invoiceStatus']);
        self::assertSame(300, $rows[0]['refundedAmount']);
        $this->post(700, 'history-full');
        $response = $this->request('GET', '/refunds');
        self::assertSame(200, $response->getStatusCode());
        $rows = $this->data($response);
        self::assertCount(2, $rows);
        $amounts = array_column($rows, 'amount');
        sort($amounts);
        self::assertSame([300, 700], $amounts);
        foreach ($rows as $row) {
            self::assertSame('refunded', $row['invoiceStatus']);
            self::assertSame(1000, $row['refundedAmount']);
            self::assertArrayNotHasKey('requestSnapshot', $row);
            self::assertArrayNotHasKey('rawData', $row);
        }
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(2, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('GET', '/refunds')->getStatusCode());
    }

    public function testValidationStates(): void
    {
        foreach (['confirmed', 'fulfilled', 'completed', 'cancelled', 'refunded'] as $status) {
            $this->order->setStatus($status); $this->em->flush();
            self::assertSame(409, $this->post(100, $status)->getStatusCode());
        }
        $this->order->setStatus('paid'); $this->em->flush();
        foreach ([['amount' => 100, 'reason' => 'test'], ['amount' => '1.00', 'reason' => 'test', 'idempotencyKey' => 'key'],
            ['amount' => 100, 'reason' => 'test', 'idempotencyKey' => 'key', 'payment' => 'mock']] as $body) {
            self::assertSame(400, $this->request('POST', '/refund', $body)->getStatusCode());
        }
        self::assertSame([], self::getContainer()->get(OrderRefundMetadata::class)->entries($this->order->getUuid()));
    }

    public function testRefundRoutesAndResponsesExplicitlyIdentifyTheTradeOrder(): void
    {
        $storeOrder = $this->storeOrder();
        $response = $this->post(100, 'explicit-trade-order');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame($this->order->getUuid(), $data['tradeOrderUuid']);
        self::assertNotSame($storeOrder->getUuid(), $data['tradeOrderUuid']);
        self::assertArrayNotHasKey('orderUuid', $data);
        $detail = $this->data($this->request('GET', '/refunds/'.$data['uuid']));
        self::assertSame($data['tradeOrderUuid'], $detail['tradeOrderUuid']);
        self::assertArrayNotHasKey('orderUuid', $detail);

        $prefix = '/api/v1/store/'.$this->store->getUuid();
        foreach ([
            ['POST', $prefix.'/trade-orders/'.$storeOrder->getUuid().'/refund'],
            ['GET', $prefix.'/trade-orders/'.$storeOrder->getUuid().'/refunds/'.$data['uuid']],
            ['POST', $prefix.'/orders/'.$this->order->getUuid().'/refund'],
            ['GET', $prefix.'/orders/'.$this->order->getUuid().'/refunds/'.$data['uuid']],
        ] as [$method, $path]) {
            $result = self::$kernel->handle(Request::create($path, $method,
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'],
                content: json_encode(['amount' => 100, 'reason' => 'Wrong order namespace', 'idempotencyKey' => 'wrong-namespace'], JSON_THROW_ON_ERROR)));
            self::assertSame(404, $result->getStatusCode(), $result->getContent());
        }
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertSame($data['uuid'], $this->data($this->post(100, 'explicit-trade-order'))['uuid']);
    }

    public function testFullRefundBlocksStoreFulfillmentEvenWithAStaleTradeEntity(): void
    {
        $storeOrder = $this->storeOrder();
        self::assertSame('succeeded', $this->data($this->post(1000, 'full-before-fulfillment'))['status']);
        // An availability check must not trust a stale in-memory source status.
        $this->order->setStatus(Order::STATUS_PAID);
        self::assertFalse(self::getContainer()->get('state_machine.store_order')->can($storeOrder, 'fulfill'));
        self::assertSame(Order::STATUS_REFUNDED, $this->order->getStatus());
        try {
            self::getContainer()->get(StoreOrderServiceInterface::class)->fulfill($storeOrder);
            self::fail('A fully refunded Store order must not be fulfilled.');
        } catch (\LogicException $error) {
            self::assertSame('accepted', $storeOrder->getOperationalStatus());
        }
    }

    public function testPartialRefundStillAllowsStoreFulfillment(): void
    {
        $storeOrder = $this->storeOrder();
        self::assertSame('succeeded', $this->data($this->post(400, 'partial-before-fulfillment'))['status']);
        self::assertTrue(self::getContainer()->get('state_machine.store_order')->can($storeOrder, 'fulfill'));
        self::getContainer()->get(StoreOrderServiceInterface::class)->fulfill($storeOrder);
        self::assertSame('fulfilled', $storeOrder->getOperationalStatus());
    }

    public function testSummaryRefreshesOutcomeAndInvoiceInsideOneSourceFirstTransaction(): void
    {
        $data = $this->data($this->post(400, 'summary-snapshot'));
        $service = self::getContainer()->get(OrderRefundService::class);
        $receipt = $service->find($this->store->getUuid(), $this->order->getUuid(), $data['uuid']);
        $this->invoice->setRefundedAmount(0)->setStatus(Invoice::STATUS_PAID);
        $listener = new class {
            /** @var list<array{class-string, bool}> */
            public array $loads = [];
            public function postLoad(PostLoadEventArgs $event): void
            {
                $entity = $event->getObject();
                if ($entity instanceof Order || $entity instanceof Invoice || $entity instanceof InvoiceRefundAttempt) {
                    $this->loads[] = [$entity::class, $event->getObjectManager()->getConnection()->isTransactionActive()];
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postLoad], $listener);
        try { $summary = $service->summary($receipt); }
        finally { $this->em->getEventManager()->removeEventListener([Events::postLoad], $listener); }
        self::assertSame('succeeded', $summary['status']);
        self::assertSame('partial_refunded', $summary['invoiceStatus']);
        self::assertSame(400, $summary['refundedAmount']);
        self::assertSame([[Order::class, true], [Invoice::class, true], [InvoiceRefundAttempt::class, true]], $listener->loads);
        self::assertFalse($this->em->getConnection()->isTransactionActive());

        $this->em->getConnection()->beginTransaction();
        try {
            self::assertSame('succeeded', $service->summary($receipt)['status']);
            self::assertSame(1, $this->em->getConnection()->getTransactionNestingLevel());
        } finally { $this->em->getConnection()->rollBack(); }
    }

    public function testSummaryFailureRollsBackOnlyItsOwnTransaction(): void
    {
        $data = $this->data($this->post(400, 'summary-failure'));
        $service = self::getContainer()->get(OrderRefundService::class);
        $receipt = $service->find($this->store->getUuid(), $this->order->getUuid(), $data['uuid']);
        $listener = new class {
            public function postLoad(PostLoadEventArgs $event): void
            {
                if ($event->getObject() instanceof Invoice) throw new \RuntimeException('Fixture summary read failure');
            }
        };
        $connection = $this->em->getConnection();
        $this->em->getEventManager()->addEventListener([Events::postLoad], $listener);
        try {
            foreach ([false, true] as $outerTransaction) {
                if ($outerTransaction) $connection->beginTransaction();
                try {
                    $service->summary($receipt);
                    self::fail('The injected summary read must fail.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Fixture summary read failure', $error->getMessage());
                    self::assertSame($outerTransaction, $connection->isTransactionActive());
                } finally {
                    if ($outerTransaction && $connection->isTransactionActive()) $connection->rollBack();
                }
            }
        } finally { $this->em->getEventManager()->removeEventListener([Events::postLoad], $listener); }
    }

    private function storeOrder(): StoreOrder
    {
        $storeOrder = new StoreOrder($this->store, $this->order->getUuid(), 'order-refunds', 'Order refunds', $this->user->getUuid(), 'CNY', 1000, []);
        $storeOrder->setOperationalStatus('accepted');
        $this->em->persist($storeOrder); $this->em->flush();
        return $storeOrder;
    }

    public function testHuifuUnknownIsQueryableAndReplayedWithoutResubmission(): void
    {
        $client = $this->configureHuifu();
        // A response without a valid signature is uncertain, never a safe failure.
        $response = $this->post(400, 'huifu-unknown');
        self::assertSame(202, $response->getStatusCode(), $response->getContent());
        $data = $this->data($response);
        self::assertSame(0, $data['refundedAmount']);
        self::assertSame(1, count($client->requests));
        self::assertSame($data['uuid'], $this->data($this->post(400, 'huifu-unknown'))['uuid']);
        self::assertSame(1, count($client->requests));
        self::assertSame(409, $this->post(100, 'other')->getStatusCode());
        $workflow = self::getContainer()->get('state_machine.order');
        self::assertFalse($workflow->can($this->order, 'fulfill'));
        $storeOrder = new StoreOrder($this->store, $this->order->getUuid(), 'order-refunds', 'Order refunds', $this->user->getUuid(), 'CNY', 1000, []);
        $storeOrder->setOperationalStatus('accepted');
        self::assertFalse(self::getContainer()->get('state_machine.store_order')->can($storeOrder, 'fulfill'));
        $provider = $this->em->getRepository(HuifuRefund::class)->findOneBy([]);
        $provider->markSucceeded(400); $this->em->flush();
        self::getContainer()->get(HuifuRefundWorkflow::class)->applyToCore($provider);
        $read = $this->data($this->request('GET', '/refunds/'.$data['uuid']));
        self::assertSame('succeeded', $read['status']);
        self::assertSame(400, $read['refundedAmount']);
        self::assertTrue($workflow->can($this->order, 'fulfill'));
        self::assertArrayNotHasKey('requestSnapshot', $read);
        self::assertArrayNotHasKey('rawData', $read);
    }

    public function testHuifuTimeoutReturnsUnknownInsteadOfBadRequest(): void
    {
        $client = $this->createMock(HuifuClientInterface::class);
        $client->expects(self::once())->method('post')->willThrowException(new \RuntimeException('Fixture transport timeout'));
        $this->configureHuifu($client);
        $response = $this->post(400, 'timeout');
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('unknown', $this->data($response)['status']);
        self::assertSame(202, $this->post(400, 'timeout')->getStatusCode());
    }

    public function testHuifuProcessingAndConfirmedFailureRemainDistinct(): void
    {
        $client = $this->configureHuifu();
        $client->responses = [
            ['data' => ['resp_code' => '00000000', 'trans_stat' => 'P']],
            ['data' => ['resp_code' => '00000000', 'trans_stat' => 'P']],
        ];
        $response = $this->post(400, 'processing');
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('processing', $this->data($response)['status']);
        self::assertSame(2, count($client->requests));
        $provider = $this->em->getRepository(HuifuRefund::class)->findOneBy([]);
        $provider->markFailed('Fixture rejection'); $this->em->flush();
        self::assertSame('failed', $this->data($this->request('GET', '/refunds/'.$this->data($response)['uuid']))['status']);
        self::assertTrue(self::getContainer()->get('state_machine.order')->can($this->order, 'fulfill'));
        $client->responses[] = ['data' => ['resp_code' => '99999999', 'resp_desc' => 'Fixture rejection']];
        $failed = $this->post(400, 'new-after-failure');
        self::assertSame(200, $failed->getStatusCode());
        self::assertSame('failed', $this->data($failed)['status']);
        self::assertSame(0, $this->invoice->getRefundedAmount());
    }

    public function testPreSubmissionFailureIsDurableAndDoesNotBlockForever(): void
    {
        $this->invoice->setPayment('huifu'); $this->em->flush();
        // No original Huifu attempt: no provider request can have been made.
        $failed = $this->post(400, 'missing-attempt');
        self::assertSame(200, $failed->getStatusCode(), $failed->getContent());
        self::assertSame('failed', $this->data($failed)['status']);
        $this->em->clear();
        self::assertSame('failed', $this->data($this->post(400, 'missing-attempt'))['status']);
        self::assertTrue(self::getContainer()->get('state_machine.order')->can($this->em->find(Order::class, $this->order->getId()), 'fulfill'));
    }

    public function testReceiptSurvivesCrashWithoutResubmittingAndBlocksFulfillment(): void
    {
        $receipt = new OrderRefundReceipt($this->store->getUuid(), $this->order->getUuid(), $this->invoice->getUuid(), $this->user->getUuid(),
            hash('sha256', 'crash'), 400, 'Customer request', 'mock');
        $this->saveReceipt($receipt);
        self::assertSame(202, $this->post(400, 'crash')->getStatusCode());
        self::assertSame(0, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertSame(0, $this->invoice->getRefundedAmount());
        self::assertFalse(self::getContainer()->get('state_machine.order')->can($this->order, 'fulfill'));
    }

    public function testCoreUnknownAttemptIsNotResubmittedByTheUnifiedEndpoint(): void
    {
        $receipt = new OrderRefundReceipt($this->store->getUuid(), $this->order->getUuid(), $this->invoice->getUuid(), $this->user->getUuid(),
            hash('sha256', 'core-unknown'), 400, 'Customer request', 'mock');
        $attempt = new InvoiceRefundAttempt(hash('sha256', $this->invoice->getUuid()."\0".$receipt->coreKey()),
            $this->invoice->getUuid(), 400, 'Customer request');
        $attempt->markUnknown();
        $this->saveReceipt($receipt);
        $this->em->persist($attempt); $this->em->flush();
        $response = $this->post(400, 'core-unknown');
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('unknown', $this->data($response)['status']);
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertSame(0, $this->invoice->getRefundedAmount());
    }

    public function testReceiptWritesPreserveUnrelatedMetadataAndRejectExternalNamespaceChanges(): void
    {
        $this->order->setMetadata(['customerNote' => 'Keep this', '_store' => ['uuid' => $this->store->getUuid()]]);
        $this->em->flush();
        $before = $this->order->getMetadata();
        $first = $this->data($this->post(100, 'json-first'));
        $entries = self::getContainer()->get(OrderRefundMetadata::class)->entries($this->order->getUuid());
        self::assertCount(1, $entries);
        self::assertSame('Keep this', $this->em->find(Order::class, $this->order->getId())->getMetadata()['customerNote']);

        // Simulate an unrelated updater with a metadata snapshot from before the
        // receipt was created. It cannot erase the current server namespace.
        $order = $this->em->find(Order::class, $this->order->getId());
        $order->setMetadata([...$before, 'customerNote' => 'Edited note']);
        $this->em->flush(); $this->em->refresh($order);
        self::assertSame($entries, $order->getMetadata()[OrderRefundMetadata::KEY]);
        self::assertSame('Edited note', $order->getMetadata()['customerNote']);

        $forged = $entries;
        $forged[hash('sha256', 'forged')] = ['uuid' => UUID::v4(), 'status' => 'succeeded'];
        $order->setMetadata([...$order->getMetadata(), OrderRefundMetadata::KEY => $forged]);
        $this->em->flush(); $this->em->refresh($order);
        self::assertSame($entries, $order->getMetadata()[OrderRefundMetadata::KEY]);

        $order->setMetadata(null);
        $this->em->flush(); $this->em->refresh($order);
        self::assertSame([OrderRefundMetadata::KEY => $entries], $order->getMetadata());
        self::assertSame($first['uuid'], $this->data($this->post(100, 'json-first'))['uuid']);
        self::assertSame(200, $this->post(100, 'json-second')->getStatusCode());
        self::assertCount(2, self::getContainer()->get(OrderRefundMetadata::class)->entries($this->order->getUuid()));
        self::assertFalse($this->em->getConnection()->createSchemaManager()->tablesExist(['store_order_refund']));
    }

    public function testNewOrdersCannotImportRefundReceiptsFromClientMetadata(): void
    {
        $this->post(100, 'original');
        $copy = (new Order())->setMetadata([...$this->order->getMetadata(), 'customerNote' => 'Retain ordinary metadata']);
        $this->em->persist($copy); $this->em->flush(); $this->em->refresh($copy);
        self::assertArrayNotHasKey(OrderRefundMetadata::KEY, $copy->getMetadata());
        self::assertSame('Retain ordinary metadata', $copy->getMetadata()['customerNote']);
    }

    public function testAuthorizationAndInvoiceIdentityAreChecked(): void
    {
        $path = '/api/v1/store/'.$this->store->getUuid().'/trade-orders/'.$this->order->getUuid().'/refund';
        self::assertSame(401, self::$kernel->handle(Request::create($path, 'POST'))->getStatusCode());
        $other = new Store('other-refunds', 'Other refunds');
        $this->em->persist($other); $this->em->flush();
        self::assertSame(403, self::$kernel->handle(Request::create(str_replace($this->store->getUuid(), $other->getUuid(), $path), 'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]))->getStatusCode());
        $this->em->find(Invoice::class, $this->invoice->getId())->setSourceId(UUID::v4()); $this->em->flush();
        self::assertSame(409, $this->post(100, 'mismatch')->getStatusCode());
        self::assertSame([], self::getContainer()->get(OrderRefundMetadata::class)->entries($this->order->getUuid()));
    }

    public function testAdministratorCanRefundWithoutStoreMembership(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM store_membership WHERE store_id = ?', [$this->store->getId()]);
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);

        $response = $this->post(100, 'administrator-refund');

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('succeeded', $this->data($response)['status']);
    }

    public function testHuifuProviderSuccessUsesTheSameSummary(): void
    {
        $client = $this->configureHuifu();
        $client->responses[] = ['data' => ['resp_code' => '00000000', 'trans_stat' => 'S', 'refund_amt' => '10.00']];
        $response = $this->post(1000, 'huifu-success');
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('succeeded', $this->data($response)['status']);
        self::assertSame('refunded', $this->order->getStatus());
        self::assertSame(1, count($client->requests));
        self::assertSame(200, $this->post(1000, 'huifu-success')->getStatusCode());
        self::assertSame(1, count($client->requests));
    }

    public function testReadAndReplayRequireCurrentPermissionAndMatchingOrder(): void
    {
        $data = $this->data($this->post(100, 'permission-test'));
        $other = (new Order())->setStatus('paid')->setTotalAmount(1000)->setCurrency('CNY');
        $this->em->persist($other);
        $this->em->persist(new OrderStoreLifecycle($other->getUuid(), $this->store->getUuid()));
        $this->em->flush();
        $path = '/api/v1/store/'.$this->store->getUuid().'/trade-orders/'.$other->getUuid().'/refunds/'.$data['uuid'];
        self::assertSame(404, self::$kernel->handle(Request::create($path, 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]))->getStatusCode());
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('GET', '/refunds/'.$data['uuid'])->getStatusCode());
        self::assertSame(403, $this->post(100, 'permission-test')->getStatusCode());
    }

    private function configureHuifu(?HuifuClientInterface $override = null): FakeHuifuClient
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $provider = new HuifuProviderProfile('Refund fixture', 'production', 'fixture-system', 'fixture-product');
        $provider->setCredentials(['merchant_private_key' => $private, 'huifu_public_key' => $public], self::getContainer()->get(HuifuSecretCipher::class));
        $merchant = new HuifuMerchant('Refund merchant', 'fixture-merchant', $provider, ['native']);
        $this->invoice->setPayment('huifu');
        $attempt = new HuifuPaymentAttempt($this->invoice, $this->store->getUuid(), $provider->getUuid(), $merchant->getUuid(),
            $merchant->getHuifuId(), 1, '20261105', 'REFUND-FIXTURE', 'native', 'T_NATIVE', 1000);
        $attempt->markSucceeded('fixture-transaction');
        foreach ([$provider, $merchant, $attempt] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $client = new FakeHuifuClient($private);
        self::getContainer()->set(HuifuClientInterface::class, $override ?? $client);
        return $client;
    }

    private function post(int $amount, string $key): Response
    {
        return $this->request('POST', '/refund', ['amount' => $amount, 'reason' => 'Customer request', 'idempotencyKey' => $key]);
    }

    private function saveReceipt(OrderRefundReceipt $receipt): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            self::getContainer()->get(OrderRefundMetadata::class)->save($this->order, $receipt);
            $this->em->getConnection()->commit();
        } catch (\Throwable $error) {
            $this->em->getConnection()->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $suffix, ?array $body = null): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/store/'.$this->store->getUuid().'/trade-orders/'.$this->order->getUuid().$suffix,
            $method, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']; }
}
