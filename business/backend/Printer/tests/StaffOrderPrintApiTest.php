<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Command\DispatchOrderPrintRequestsCommand;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\OrderPrintRequestWriter;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\PrinterProviderInterface;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use NsUltimate\Business\Store\Entity\StaffOperation;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffOrderPrintApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Store $other;
    private User $user;
    private Order $order;
    private PrinterOrderRule $rule;
    private PrinterTemplate $template;
    private PrinterDevice $device;
    private string $token;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->store = new Store('print-store', 'Print store');
        $this->other = new Store('other-print-store', 'Other store');
        $this->user = (new User())->setUsername('cashier-print')->setEmail('cashier-print@example.test');
        $permission = new Permission('store:order:print', 'store', 'order', 'print', 'Print orders');
        $role = (new Role('test-printer', 'Order printer', Role::SCOPE_STORE))->addPermission($permission);
        $account = new PrinterAccount('Test account', 'test', '');
        $this->device = new PrinterDevice($account, 'Receipt', 'STAFF-PRINT', 'receipt', $this->store->getUuid());
        $this->template = new PrinterTemplate('Order receipt', 'receipt', ['kind' => 'receipt', 'nodes' => [
            ['type' => 'field', 'path' => 'order.number'], ['type' => 'line'],
            ['type' => 'each', 'path' => 'order.items', 'children' => [['type' => 'field', 'path' => 'item.name']]],
        ]]);
        $this->rule = (new PrinterOrderRule())->setStoreUuid($this->store->getUuid())->setName('Receipt')->setEnabled(true)
            ->setEvents(['paid'])->setDeviceUuid($this->device->getUuid())->setTemplateUuid($this->template->getUuid());
        $this->order = (new Order())->setStatus(Order::STATUS_CONFIRMED)
            ->addItem((new OrderItem())->setProductSnapshot(['name' => 'Tea'])->setQuantity(2));
        foreach ([$this->store, $this->other, $this->user, $permission, $role, $account, $this->device, $this->template, $this->rule, $this->order,
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_CLERK),
            new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid()),
            new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
    }

    public function testStaffQueuesTrustedSnapshotAndSameCommandNeverPrintsAgain(): void
    {
        $options = $this->request('GET', '/print-options');
        self::assertSame(200, $options->getStatusCode(), $options->getContent());
        $items = $this->data($options)['items'];
        self::assertCount(1, $items);
        self::assertSame($this->rule->getUuid(), $items[0]['ruleUuid']);
        self::assertArrayNotHasKey('credentialsCipher', $items[0]);
        $payload = $this->payload();
        $response = $this->request('POST', $this->path(), $payload);
        self::assertSame(202, $response->getStatusCode(), $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $result = $this->data($response);
        self::assertSame('queued', $result['status']);
        self::assertCount(1, $result['requestUuids']);
        $queued = $this->em->getRepository(PrinterOrderRequest::class)->findOneBy(['uuid' => $result['requestUuids'][0]]);
        self::assertSame('Tea', $queued->getPrintData()['order']['items'][0]['name']);
        self::assertSame('manual', $queued->getTrigger());
        self::assertSame($this->user->getUuid(), $queued->getConfiguration()['actorUuid']);
        self::assertSame($this->template->getDefinition(), $queued->getConfiguration()['templateSnapshot']);
        self::assertSame(0, $this->em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));

        // Replays remain historical even if the output is later disabled.
        $this->rule->setEnabled(false);
        $this->em->flush();
        self::assertSame($result, $this->data($this->request('POST', $this->path(), $payload)));
        $recovered = $this->data($this->request('GET', '/commands/'.$payload['commandUuid']));
        self::assertSame('succeeded', $recovered['status']);
        self::assertSame($result['requestUuids'], $recovered['result']['requestUuids']);
        self::assertSame(409, $this->request('POST', $this->path(), [...$payload, 'reason' => 'Different request'])->getStatusCode());
        self::assertSame(1, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testStaffPrintsThroughExistingDispatcherAndExplicitNewCommandAllowsReprint(): void
    {
        $payload = $this->payload();
        self::assertSame(202, $this->request('POST', $this->path(), $payload)->getStatusCode());
        $provider = $this->createMock(PrinterProviderInterface::class);
        $provider->method('code')->willReturn('xpyun');
        $provider->expects(self::once())->method('submitJob')->willReturn(['providerOrderId' => 'staff-print-1']);
        $jobs = new PrinterJobService($this->em, self::getContainer()->get(PrintDocumentRenderer::class), new PrinterProviderRegistry([$provider]));
        $dispatch = new CommandTester(new DispatchOrderPrintRequestsCommand($this->em, $jobs, new OrderPrintRequestWriter($this->em->getConnection())));
        self::assertSame(0, $dispatch->execute([]));
        self::assertSame(0, $dispatch->execute([]));
        $job = $this->em->getRepository(PrinterJob::class)->findOneBy([]);
        self::assertSame(PrinterJob::STATUS_SUBMITTED, $job->getStatus());
        self::assertStringContainsString('Tea', $job->getRenderedContent());
        self::assertSame(202, $this->request('POST', $this->path(), $payload)->getStatusCode());
        self::assertSame(1, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
        self::assertSame(202, $this->request('POST', $this->path(), [...$payload, 'commandUuid' => UUID::v4()])->getStatusCode());
        self::assertSame(2, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testPermissionMembershipAndOrderOwnershipAreRequired(): void
    {
        self::assertSame(401, $this->request('POST', $this->path(), $this->payload(), authenticated: false)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/print-options', storeUuid: $this->other->getUuid())->getStatusCode());
        $foreign = (new Order())->setStatus(Order::STATUS_PAID);
        $this->em->persist($foreign);
        $this->em->persist(new OrderStoreLifecycle($foreign->getUuid(), $this->other->getUuid()));
        $this->em->flush();
        self::assertSame(404, $this->request('POST', '/trade-orders/'.$foreign->getUuid().'/print', $this->payload())->getStatusCode());
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        self::assertSame(0, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testInvalidOverridesDisabledOutputsAndInvalidStatesCannotQueue(): void
    {
        foreach ([['data' => ['order' => []]], ['copies' => 10], ['deviceUuid' => UUID::v4()], ['reason' => ''], ['commandUuid' => 'invalid']] as $changes) {
            self::assertSame(422, $this->request('POST', $this->path(), [...$this->payload(), ...$changes])->getStatusCode());
        }
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]);
        $order->setStatus(Order::STATUS_CANCELLED);
        $this->em->flush();
        self::assertSame(409, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]);
        $order->setStatus(Order::STATUS_CONFIRMED);
        $device = $this->em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $this->device->getUuid()]);
        $device->setEnabled(false);
        $this->em->flush();
        self::assertSame([], $this->data($this->request('GET', '/print-options'))['items']);
        self::assertSame(409, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        self::assertSame(0, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
        self::assertSame(0, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testTicketModeAndFiltersAreAppliedAndOversizedCommandsRollBack(): void
    {
        $this->template->setTicketMode(PrinterTemplate::TICKET_PER_UNIT);
        $this->em->flush();
        $response = $this->request('POST', $this->path(), $this->payload());
        self::assertSame(202, $response->getStatusCode(), $response->getContent());
        self::assertCount(2, $this->data($response)['requestUuids']);
        $this->template->setItemFilter('entity.getQuantity() > 10');
        $this->em->flush();
        self::assertSame(409, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        $template = $this->em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $this->template->getUuid()]);
        $template->setItemFilter(null);
        $rule = $this->em->getRepository(PrinterOrderRule::class)->findOneBy(['uuid' => $this->rule->getUuid()]);
        $rule->setCopies(100);
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]);
        $order->getItems()->first()->setQuantity(6);
        $this->em->flush();
        self::assertSame(422, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        self::assertSame(2, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testCrossStoreRulesAndDevicesAndDisabledTemplatesAreRejected(): void
    {
        $foreignRule = (new PrinterOrderRule())->setStoreUuid($this->other->getUuid())->setName('Foreign')->setEnabled(true)
            ->setDeviceUuid($this->device->getUuid())->setTemplateUuid($this->template->getUuid());
        $this->em->persist($foreignRule);
        $this->em->flush();
        self::assertSame(404, $this->request('POST', $this->path(), [...$this->payload(), 'ruleUuid' => $foreignRule->getUuid()])->getStatusCode());
        $device = $this->em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $this->device->getUuid()]);
        $device->setStoreUuid($this->other->getUuid());
        $this->em->flush();
        self::assertSame([], $this->data($this->request('GET', '/print-options'))['items']);
        self::assertSame(409, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        $device = $this->em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $this->device->getUuid()]);
        $device->setStoreUuid($this->store->getUuid());
        $template = $this->em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $this->template->getUuid()]);
        $template->setEnabled(false);
        $this->em->flush();
        self::assertSame(409, $this->request('POST', $this->path(), $this->payload())->getStatusCode());
        self::assertSame(0, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testRevokedMembershipBlocksNewPrintsAndReceiptRecoveryDespiteRetainedGrant(): void
    {
        $payload = $this->payload();
        self::assertSame(202, $this->request('POST', $this->path(), $payload)->getStatusCode());
        $this->em->getConnection()->executeStatement('DELETE FROM store_membership');
        self::assertSame(403, $this->request('GET', '/print-options')->getStatusCode());
        self::assertSame(403, $this->request('POST', $this->path(), $payload)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/commands/'.$payload['commandUuid'])->getStatusCode());
        self::assertSame(1, $this->em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    private function path(): string { return '/trade-orders/'.$this->order->getUuid().'/print'; }

    /** @return array<string, mixed> */
    private function payload(): array { return ['commandUuid' => UUID::v4(), 'ruleUuid' => $this->rule->getUuid(), 'reason' => 'Customer receipt']; }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null, ?string $storeUuid = null, bool $authenticated = true): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/store/'.($storeUuid ?? $this->store->getUuid()).'/operations'.$path, $method, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $authenticated ? 'Bearer '.$this->token : ''],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']; }
}
