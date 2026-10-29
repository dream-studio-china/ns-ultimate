<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Store\Entity\Store;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderAcceptedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Adapter\StoreOrderAcceptedPrintHandler;
use NsUltimate\Business\Printer\Command\DispatchOrderPrintRequestsCommand;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\OrderPrintRequestWriter;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\PrinterProviderException;
use NsUltimate\Business\Printer\Service\PrinterProviderInterface;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/** Exercises real event subscribers without contacting a physical printer. */
final class PrinterRuleLifecycleIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($em->getConnection()->createSchemaManager()->listTableNames() !== []) { $schema->dropSchema($metadata); }
        $schema->createSchema($metadata);
    }

    public function testActualWorkflowAndAcceptanceProduceOneFailedOfflineJob(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $store = new Store('printer-lifecycle', 'Printer lifecycle');
        $account = new PrinterAccount('Test account', 'test', '');
        $device = new PrinterDevice($account, 'Test printer', 'lifecycle-serial', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Test receipt', PrinterDevice::KIND_RECEIPT, [
            'kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Offline receipt']],
        ]);
        $order = (new Order())->setStatus(Order::STATUS_PENDING)->setMetadata(['counterSettlement' => true]);
        $admin = (new User())->setEmail('printer-lifecycle@example.test')->setUsername('printer-lifecycle')->setRoles(['ROLE_ADMIN']);
        foreach ([$store, $account, $device, $template, $order, $admin, new OrderStoreLifecycle($order->getUuid(), $store->getUuid())] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $body = ['storeUuid' => $store->getUuid(), 'name' => 'Offline test', 'enabled' => true,
            'events' => ['confirmed', 'paid', 'accepted'], 'match' => 'all',
            'conditions' => [['field' => 'metadata.counterSettlement', 'operator' => 'equals', 'value' => true]],
            'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1];
        $request = Request::create('/api/v1/manage/printer/order-rules', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $ruleUuid = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['uuid'];
        $workflow = self::getContainer()->get('state_machine.order');
        $em->wrapInTransaction(fn () => $workflow->apply($order, 'confirm'));
        $em->wrapInTransaction(fn () => $workflow->apply($order, 'pay'));
        $handler = self::getContainer()->get(StoreOrderAcceptedPrintHandler::class);
        $message = new StoreOrderAcceptedMessage(['payload' => ['orderUuid' => $order->getUuid(), 'storeUuid' => $store->getUuid()]]);
        $handler($message);
        $handler($message);
        $requests = $em->getRepository(PrinterOrderRequest::class)->findBy(['orderUuid' => $order->getUuid()]);
        self::assertCount(1, $requests);
        self::assertSame('confirmed', $requests[0]->getTrigger());
        self::assertSame($ruleUuid, $requests[0]->getCaseKey());
        $template->setDefinition(['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Changed later']]]);
        $em->flush();

        $provider = $this->createMock(PrinterProviderInterface::class);
        $provider->method('code')->willReturn(PrinterAccount::PROVIDER_XPYUN);
        $provider->expects(self::once())->method('submitJob')->willThrowException(new PrinterProviderException('offline_test'));
        $jobs = new PrinterJobService($em, new PrintDocumentRenderer(), new PrinterProviderRegistry([$provider]));
        $command = new CommandTester(new DispatchOrderPrintRequestsCommand($em, $jobs, new OrderPrintRequestWriter($em->getConnection())));
        self::assertSame(0, $command->execute([]));
        $allJobs = $em->getRepository(PrinterJob::class)->findAll();
        self::assertCount(1, $allJobs);
        self::assertSame(PrinterJob::STATUS_FAILED, $allJobs[0]->getStatus());
        self::assertSame('offline_test', $allJobs[0]->getErrorCode());
        self::assertStringContainsString('Offline receipt', $allJobs[0]->getRenderedContent());
        self::assertStringNotContainsString('Changed later', $allJobs[0]->getRenderedContent());
        self::assertSame(0, $command->execute([]));
        self::assertCount(1, $em->getRepository(PrinterJob::class)->findAll());
    }
}
