<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Command\DispatchOrderPrintRequestsCommand;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
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

    public function testDispatchRecordsFailedJobWithoutRealPrinter(): void
    {
        [, $payload] = $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $payload['templateUuid']]);
        $template->setDefinition(['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Offline test']]]);
        $request = new PrinterOrderRequest(
            hash('sha256', 'offline-test'), 'order-test', $payload['storeUuid'], 'test-rule', 'paid',
            ['scene' => 'order', 'deviceUuid' => $payload['deviceUuid'], 'templateUuid' => $payload['templateUuid'], 'copies' => 1],
            ['order' => ['number' => 'offline-test']],
        );
        $em->persist($request);
        $em->flush();
        $provider = $this->createMock(PrinterProviderInterface::class);
        $provider->method('code')->willReturn(PrinterAccount::PROVIDER_XPYUN);
        $provider->expects(self::once())->method('submitJob')->willThrowException(new PrinterProviderException('offline_test'));
        $jobs = new PrinterJobService($em, new PrintDocumentRenderer(), new PrinterProviderRegistry([$provider]));
        $command = new CommandTester(new DispatchOrderPrintRequestsCommand($em, $jobs));
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
        $request = new PrinterOrderRequest($key, 'order', 'store', 'rule', 'paid', ['scene' => 'order'], ['version' => 1]);
        $duplicate = new PrinterOrderRequest($key, 'order', 'store', 'rule', 'accepted', ['scene' => 'order'], ['version' => 2]);
        $connection->beginTransaction();
        $writer->insert($request);
        $writer->insert($duplicate);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        self::assertSame('paid', $connection->fetchOne('SELECT event_trigger FROM printer_order_request'));
        self::assertSame(['version' => 1], json_decode($connection->fetchOne('SELECT print_data FROM printer_order_request'), true));
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        $this->expectException(\LogicException::class);
        $writer->insert($request);
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
            'events' => ['paid'], 'match' => 'all', 'conditions' => [], 'scene' => 'order',
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
