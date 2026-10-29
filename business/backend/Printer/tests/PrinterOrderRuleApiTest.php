<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Event\OrderPaidEvent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Command\DispatchOrderPrintRequestsCommand;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Adapter\OrderPrintRuleEventAdapter;
use NsUltimate\Business\Printer\Service\OrderPrintRuleEnqueuer;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
use NsUltimate\Business\Printer\Service\OrderPrintRuleSelector;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use NsUltimate\Business\Printer\Service\OrderPrintRequestWriter;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\PrinterProviderException;
use NsUltimate\Business\Printer\Service\PrinterProviderInterface;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Workflow\Event\CompletedEvent;

final class PrinterOrderRuleApiTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($em->getConnection()->createSchemaManager()->listTableNames() !== []) { $tool->dropSchema($metadata); }
        $tool->createSchema($metadata);
    }

    public function testCreateAndUpdateRuleAndRejectInvalidConditions(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $response = $this->request('PUT', '/api/v1/manage/printer/order-rules/'.$data['id'], $token, ['name' => 'Updated']);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $updated = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame($data['uuid'], $updated['uuid']);
        self::assertSame('Updated', $updated['name']);
        $response = $this->request('PUT', '/api/v1/manage/printer/order-rules/'.$data['id'], $token, [
            'conditions' => [['field' => 'metadata.bad..path', 'operator' => 'exists']],
        ]);
        self::assertSame(400, $response->getStatusCode(), $response->getContent());
    }

    public function testRejectsDeviceOwnedByAnotherStore(): void
    {
        [$token, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Store('other-printer-store', 'Other');
        $em->persist($other);
        $em->flush();
        $payload['storeUuid'] = $other->getUuid();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(400, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $em->getRepository(PrinterOrderRule::class)->count([]));
    }

    public function testRejectsMismatchedTemplateKind(): void
    {
        [$token, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = new PrinterTemplate('Label', PrinterDevice::KIND_LABEL, ['kind' => 'label', 'nodes' => []]);
        $em->persist($template);
        $em->flush();
        $payload['templateUuid'] = $template->getUuid();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(400, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $em->getRepository(PrinterOrderRule::class)->count([]));
    }

    public function testStoreRuleLimitIsEnforced(): void
    {
        [$token, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 50; ++$i) {
            $em->persist((new PrinterOrderRule())->setStoreUuid($payload['storeUuid'])->setName('Rule '.$i)
                ->setEnabled(false)->setEvents(['paid'])->setMatch('all')->setConditions([])
                ->setDeviceUuid($payload['deviceUuid'])->setTemplateUuid($payload['templateUuid'])->setCopies(1));
        }
        $em->flush();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(400, $response->getStatusCode(), $response->getContent());
    }

    public function testFormNullMarkerRestoresNullConditionValue(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['conditions'] = [['field' => 'metadata.optional', 'operator' => 'equals', 'valueKind' => 'null']];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame([['field' => 'metadata.optional', 'operator' => 'equals', 'value' => null]], $data['conditions']);
    }

    public function testNonAdminCannotCreateRules(): void
    {
        [, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('printer-rule-user@example.test')->setUsername('printer-rule-user');
        $em->persist($user);
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(403, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $em->getRepository(PrinterOrderRule::class)->count([]));
    }

    public function testStoredRuleCanBeLoadedAndMatchedByStore(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['paid', 'accepted'];
        $payload['conditions'] = [['field' => 'metadata.counterSettlement', 'operator' => 'equals', 'value' => true]];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $rules = $em->getRepository(PrinterOrderRule::class)->findBy(['storeUuid' => $payload['storeUuid'], 'enabled' => true]);
        self::assertCount(1, $rules);
        $matcher = self::getContainer()->get(OrderPrintRuleMatcher::class);
        $order = (new Order())->setMetadata(['counterSettlement' => true]);
        self::assertTrue($matcher->matches($rules[0]->toRule(), 'paid', $order));
        self::assertTrue($matcher->matches($rules[0]->toRule(), 'accepted', $order));
        self::assertFalse($matcher->matches($rules[0]->toRule(), 'confirmed', $order));
        $order->setMetadata(['counterSettlement' => false]);
        self::assertFalse($matcher->matches($rules[0]->toRule(), 'paid', $order));
        self::assertCount(0, $em->getRepository(PrinterOrderRule::class)->findBy(['storeUuid' => 'another-store', 'enabled' => true]));
    }

    public function testSelectorIgnoresDisabledInvalidAndOtherStoreRules(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['paid', 'accepted'];
        $payload['conditions'] = [['field' => 'status', 'operator' => 'equals', 'value' => 'paid']];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $uuid = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['uuid'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $base = static fn (): PrinterOrderRule => (new PrinterOrderRule())
            ->setStoreUuid($payload['storeUuid'])->setName('Additional')->setEnabled(true)
            ->setEvents(['paid'])->setMatch('all')->setConditions([])
            ->setDeviceUuid($payload['deviceUuid'])->setTemplateUuid($payload['templateUuid'])->setCopies(1);
        $em->persist($base()->setEnabled(false));
        $em->persist($base()->setStoreUuid('another-store'));
        $em->persist($base()->setCopies(0));
        $em->persist($base()->setConditions([['field' => 'metadata.bad..path', 'operator' => 'exists']]));
        $em->flush();
        $em->clear();
        $matcher = new OrderPrintRuleMatcher();
        $selector = new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher);
        $order = (new Order())->setStatus(Order::STATUS_PAID);
        $selected = $selector->select($payload['storeUuid'], 'paid', $order);
        self::assertCount(1, $selected);
        self::assertSame($uuid, $selected[0]->getUuid());
        self::assertCount(1, $selector->select($payload['storeUuid'], 'accepted', $order));
        self::assertSame([], $selector->select($payload['storeUuid'], 'confirmed', $order));
        $order->setStatus(Order::STATUS_CONFIRMED);
        self::assertSame([], $selector->select($payload['storeUuid'], 'paid', $order));
    }

    public function testDispatchRecordsFailedJobWithoutRealPrinter(): void
    {
        [, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $template->setDefinition(['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Offline test']]]);
        $request = new PrinterOrderRequest(
            hash('sha256', 'offline-test'), 'order-test', $payload['storeUuid'], 'test-rule', 'paid',
            ['ticketMode' => 'single', 'deviceUuid' => $payload['deviceUuid'], 'templateUuid' => $payload['templateUuid'], 'copies' => 1, 'templateSnapshot' => $template->getDefinition()],
            ['order' => ['number' => 'offline-test']],
        );
        $em->persist($request);
        $em->flush();
        $provider = $this->createMock(PrinterProviderInterface::class);
        $provider->method('code')->willReturn(PrinterAccount::PROVIDER_XPYUN);
        $provider->expects(self::once())->method('submitJob')->willThrowException(new PrinterProviderException('offline_test'));
        $jobs = new PrinterJobService($em, new PrintDocumentRenderer(), new PrinterProviderRegistry([$provider]));
        $command = new CommandTester(new DispatchOrderPrintRequestsCommand($em, $jobs, new OrderPrintRequestWriter($em->getConnection())));
        self::assertSame(0, $command->execute([]));
        $em->clear();
        $stored = $em->getRepository(PrinterOrderRequest::class)->findOneBy(['requestKey' => hash('sha256', 'offline-test')]);
        $allJobs = $em->getRepository(PrinterJob::class)->findAll();
        self::assertSame(PrinterOrderRequest::STATUS_PROCESSED, $stored->getStatus(), $stored->getErrorMessage() ?? '');
        self::assertCount(1, $allJobs);
        self::assertSame(PrinterJob::STATUS_FAILED, $allJobs[0]->getStatus());
        self::assertSame('offline_test', $allJobs[0]->getErrorCode());
        self::assertNull($allJobs[0]->getProviderOrderId());
        self::assertSame(0, $command->execute([]));
        self::assertCount(1, $em->getRepository(PrinterJob::class)->findAll());
    }

    public function testRequestWriterDeduplicatesAndRollsBackWithOrderTransaction(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $writer = new OrderPrintRequestWriter($connection);
        $key = hash('sha256', 'order-and-rule');
        $request = new PrinterOrderRequest($key, 'order', 'store', 'rule', 'paid', ['ticketMode' => 'single'], ['version' => 1]);
        $duplicate = new PrinterOrderRequest($key, 'order', 'store', 'rule', 'accepted', ['ticketMode' => 'single'], ['version' => 2]);
        $connection->beginTransaction();
        self::assertTrue($writer->claim('order', 'rule'));
        self::assertFalse($writer->claim('order', 'rule'));
        self::assertTrue($writer->claim('order', 'another-rule'));
        $writer->insert($request);
        $writer->insert($duplicate);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        self::assertSame('paid', $connection->fetchOne('SELECT event_trigger FROM printer_order_request'));
        self::assertSame(['version' => 1], json_decode($connection->fetchOne('SELECT print_data FROM printer_order_request'), true));
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_rule_claim'));
        $connection->executeStatement(
            "INSERT INTO printer_order_rule_claim (claim_key, token, created_at) VALUES ('old-claim', 'token', '2000-01-01 00:00:00')",
        );
        self::assertSame(1, $writer->pruneClaims(new \DateTimeImmutable('2026-10-29 00:00:00')));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_rule_claim'));
        $this->expectException(\LogicException::class);
        $writer->insert($request);
    }

    public function testRuleEnqueueCreatesOneOfflineFailedJobAcrossEvents(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['confirmed', 'paid', 'accepted'];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $uuid = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['uuid'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $store = $em->getRepository(Store::class)->findOneBy(['uuid' => $payload['storeUuid']]);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $template->setDefinition(['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Offline test']]]);
        $em->flush();
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $matcher = new OrderPrintRuleMatcher();
        $enqueuer = new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        );
        $em->getConnection()->beginTransaction();
        $enqueuer->enqueue($order, $store, 'confirmed');
        $order->setStatus(Order::STATUS_PAID);
        $enqueuer->enqueue($order, $store, 'paid');
        $enqueuer->enqueue($order, $store, 'accepted');
        $em->getConnection()->commit();
        $requests = $em->getRepository(PrinterOrderRequest::class)->findAll();
        self::assertCount(1, $requests);
        self::assertSame($uuid, $requests[0]->getCaseKey());
        self::assertSame('confirmed', $requests[0]->getTrigger());
        self::assertSame('confirmed', $requests[0]->getPrintData()['order']['status']);
        self::assertSame($uuid, $requests[0]->getConfiguration()['id']);
        $provider = $this->createMock(PrinterProviderInterface::class);
        $provider->method('code')->willReturn(PrinterAccount::PROVIDER_XPYUN);
        $provider->expects(self::once())->method('submitJob')->willThrowException(new PrinterProviderException('offline_test'));
        $jobs = new PrinterJobService($em, new PrintDocumentRenderer(), new PrinterProviderRegistry([$provider]));
        $command = new CommandTester(new DispatchOrderPrintRequestsCommand($em, $jobs, new OrderPrintRequestWriter($em->getConnection())));
        self::assertSame(0, $command->execute([]));
        $result = $em->getRepository(PrinterJob::class)->findAll();
        self::assertCount(1, $result);
        self::assertSame(PrinterJob::STATUS_FAILED, $result[0]->getStatus());
    }

    public function testEventAdapterUsesTrustedStoreAndDeduplicatesAcrossEvents(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['confirmed', 'paid', 'accepted'];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $enqueuer = new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        );
        $adapter = new OrderPrintRuleEventAdapter($em, $enqueuer);
        $adapter->onAccepted($order->getUuid(), 'wrong-store');
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
        $em->getConnection()->beginTransaction();
        $adapter->onConfirmed($order);
        $order->setStatus(Order::STATUS_PAID);
        $adapter->onPaid($order);
        $em->getConnection()->commit();
        $adapter->onAccepted($order->getUuid(), $payload['storeUuid']);
        $requests = $em->getRepository(PrinterOrderRequest::class)->findAll();
        self::assertCount(1, $requests);
        self::assertSame('confirmed', $requests[0]->getTrigger());
        self::assertSame($payload['storeUuid'], $requests[0]->getStoreUuid());
    }

    public function testWorkflowConfirmedEventUsesNewStatusAndRollsBackPrintRequest(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['confirmed'];
        $payload['conditions'] = [['field' => 'status', 'operator' => 'equals', 'value' => 'confirmed']];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_PENDING);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $listener = static function (CompletedEvent $event) use ($adapter): void {
            $subject = $event->getSubject();
            if ($subject instanceof Order) { $adapter->onConfirmed($subject); }
        };
        $dispatcher->addListener('workflow.order.completed.confirm', $listener);
        try {
            $connection = $em->getConnection();
            $connection->beginTransaction();
            self::getContainer()->get('state_machine.order')->apply($order, 'confirm');
            self::assertSame(1, $em->getRepository(PrinterOrderRequest::class)->count([]));
            $request = $em->getRepository(PrinterOrderRequest::class)->findOneBy([]);
            self::assertSame('confirmed', $request->getPrintData()['order']['status']);
            $connection->rollBack();
            self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_rule_claim'));
        } finally {
            $dispatcher->removeListener('workflow.order.completed.confirm', $listener);
        }
    }

    public function testPaymentCompletedEventSeesPaidStatusAndQueuesRule(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['conditions'] = [['field' => 'status', 'operator' => 'equals', 'value' => 'paid']];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $domainStatus = null;
        $domainListener = static function (OrderPaidEvent $event) use (&$domainStatus): void { $domainStatus = $event->order->getStatus(); };
        $completedListener = static function (CompletedEvent $event) use ($adapter): void {
            $subject = $event->getSubject();
            if ($subject instanceof Order) { $adapter->onPaid($subject); }
        };
        $dispatcher->addListener(OrderPaidEvent::class, $domainListener);
        $dispatcher->addListener('workflow.order.completed.pay', $completedListener);
        try {
            $em->wrapInTransaction(fn () => self::getContainer()->get('state_machine.order')->apply($order, 'pay'));
            self::assertSame(Order::STATUS_CONFIRMED, $domainStatus);
            $request = $em->getRepository(PrinterOrderRequest::class)->findOneBy(['orderUuid' => $order->getUuid()]);
            self::assertInstanceOf(PrinterOrderRequest::class, $request);
            self::assertSame('paid', $request->getTrigger());
            self::assertSame('paid', $request->getPrintData()['order']['status']);
        } finally {
            $dispatcher->removeListener(OrderPaidEvent::class, $domainListener);
            $dispatcher->removeListener('workflow.order.completed.pay', $completedListener);
        }
    }

    public function testAcceptedEventCanQueueAndReplayOutsideTransaction(): void
    {
        [$token, $payload] = $this->fixtures();
        $payload['events'] = ['accepted'];
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_PENDING);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $adapter->onAccepted($order->getUuid(), $payload['storeUuid']);
        $adapter->onAccepted($order->getUuid(), $payload['storeUuid']);
        $requests = $em->getRepository(PrinterOrderRequest::class)->findAll();
        self::assertCount(1, $requests);
        self::assertSame('accepted', $requests[0]->getTrigger());
        self::assertSame('pending', $requests[0]->getPrintData()['order']['status']);
        self::assertFalse($em->getConnection()->isTransactionActive());
    }

    public function testExcessPerItemTicketsRecordFailureWithoutBreakingOrderTransaction(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $template->setTicketMode(PrinterTemplate::TICKET_PER_UNIT);
        $order = (new Order())->setStatus(Order::STATUS_PAID)->addItem((new OrderItem())->setQuantity(501));
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $em->wrapInTransaction(fn () => $adapter->onPaid($order));
        $requests = $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $order->getUuid()]);
        self::assertCount(1, $requests);
        self::assertSame(PrinterOrderRequest::STATUS_FAILED, $requests[0]->getStatus());
        self::assertStringContainsString('500 tickets', $requests[0]->getErrorMessage());
    }

    public function testRuleFailureNeverRollsBackOrderTransaction(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        // Force a storage failure inside the order transaction.
        $em->getConnection()->executeStatement('DROP TABLE printer_order_rule_claim');
        $order->setStatus(Order::STATUS_PAID);
        $em->wrapInTransaction(function () use ($em, $adapter, $order): void {
            $adapter->onPaid($order);
            $order->setNotes('payment committed');
            $em->flush();
        });
        $em->clear();
        $stored = $em->getRepository(Order::class)->findOneBy(['uuid' => $order->getUuid()]);
        self::assertSame('payment committed', $stored->getNotes());
        self::assertSame(Order::STATUS_PAID, $stored->getStatus());
    }

    public function testRuleEventOutsideTransactionStartsItsOwn(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())->setStatus(Order::STATUS_PAID);
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        self::assertFalse($em->getConnection()->isTransactionActive());
        $adapter->onPaid($order);
        self::assertFalse($em->getConnection()->isTransactionActive());
        self::assertCount(1, $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $order->getUuid()]));
    }

    public function testTemplatePerUnitCreatesOneRequestPerItemUnit(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $template->setTicketMode(PrinterTemplate::TICKET_PER_UNIT);
        $order = (new Order())->setStatus(Order::STATUS_PAID)
            ->addItem((new OrderItem())->setQuantity(2))
            ->addItem((new OrderItem())->setQuantity(1));
        $em->persist($order);
        $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $adapter->onPaid($order);
        $requests = $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $order->getUuid()]);
        self::assertCount(3, $requests);
        $tickets = [];
        foreach ($requests as $request) {
            self::assertCount(1, $request->getPrintData()['order']['items']);
            self::assertSame('per_unit', $request->getConfiguration()['ticketMode']);
            $item = $request->getPrintData()['item'];
            $tickets[] = $item['index'].'/'.$item['total'];
        }
        sort($tickets);
        self::assertSame(['1/1', '1/2', '2/2'], $tickets);
    }

    public function testItemFilterEvaluatesPerItemAndSkipsOrdersWithoutMatches(): void
    {
        [$token, $payload] = $this->fixtures();
        $response = $this->request('POST', '/api/v1/manage/printer/order-rules', $token, $payload);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $ruleUuid = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['uuid'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $filter = 'entity.getProductSettings()["kitchenPrinterEnable"] == true';
        $template->setItemFilter($filter);
        $skipped = (new Order())->setStatus(Order::STATUS_PAID)
            ->addItem((new OrderItem())->setProductSnapshot(['name' => 'Drink']));
        $printed = (new Order())->setStatus(Order::STATUS_PAID)
            ->addItem((new OrderItem())->setProductSnapshot(['name' => 'Burger', 'settings' => ['kitchenPrinterEnable' => true]]));
        foreach ([$skipped, $printed] as $order) {
            $em->persist($order);
            $em->persist(new OrderStoreLifecycle($order->getUuid(), $payload['storeUuid']));
        }
        $em->flush();
        $matcher = new OrderPrintRuleMatcher();
        $adapter = new OrderPrintRuleEventAdapter($em, new OrderPrintRuleEnqueuer(
            new OrderPrintRuleSelector($em, new OrderPrintRuleValidator($matcher), $matcher),
            new OrderPrintRequestWriter($em->getConnection()), new TradeOrderPrintAdapter($em), $em,
        ));
        $adapter->onPaid($skipped);
        $adapter->onPaid($printed);
        self::assertCount(0, $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $skipped->getUuid()]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM printer_order_rule_claim WHERE claim_key = ?',
            [hash('sha256', json_encode([$skipped->getUuid(), $ruleUuid], JSON_THROW_ON_ERROR))],
        ));
        $requests = $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $printed->getUuid()]);
        self::assertCount(1, $requests);
        self::assertSame($filter, $requests[0]->getConfiguration()['itemFilter']);
        self::assertCount(1, $requests[0]->getPrintData()['order']['items']);
    }

    public function testTemplateItemFilterSyntaxIsValidatedOnSave(): void
    {
        [$token] = $this->fixtures();
        $base = [
            'name' => 'Filtered receipt', 'kind' => 'receipt',
            'definition' => ['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Test']]],
            'sampleData' => [], 'ticketMode' => 'single',
        ];
        $invalid = $this->request('POST', '/api/v1/manage/printer/templates', $token, $base + ['itemFilter' => 'entity.getQuantity( >']);
        self::assertSame(400, $invalid->getStatusCode(), $invalid->getContent());
        $invalidType = $this->request('POST', '/api/v1/manage/printer/templates', $token, $base + ['itemFilter' => ['kitchen']]);
        self::assertSame(400, $invalidType->getStatusCode(), $invalidType->getContent());
        $valid = $this->request('POST', '/api/v1/manage/printer/templates', $token, $base + ['itemFilter' => 'entity.getQuantity() > 1']);
        self::assertSame(201, $valid->getStatusCode(), $valid->getContent());
        $data = json_decode($valid->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame('entity.getQuantity() > 1', $data['itemFilter']);
    }

    private function fixtures(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('printer-rule@example.test')->setUsername('printer-rule-admin')->setRoles(['ROLE_ADMIN']);
        $store = new Store('printer-rule-store', 'Printer Rule Store');
        $account = new PrinterAccount('Local test', 'test', '');
        $device = new PrinterDevice($account, 'Printer', 'test-serial', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Receipt');
        foreach ([$admin, $store, $account, $device, $template] as $entity) { $em->persist($entity); }
        $em->flush();
        return [self::getContainer()->get(TokenManager::class)->createAccessToken($admin), [
            'storeUuid' => $store->getUuid(), 'name' => 'Kitchen', 'enabled' => true,
            'events' => ['paid'], 'match' => 'all', 'conditions' => [],
            'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1,
        ]];
    }

    private function request(string $method, string $path, string $token, array $data): Response
    {
        return self::$kernel->handle(Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
        ], json_encode($data, JSON_THROW_ON_ERROR)));
    }
}
