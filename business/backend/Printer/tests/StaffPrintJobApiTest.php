<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrinterProviderInterface;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffPrintJobApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Store $other;
    private User $user;
    private PrinterJob $job;
    private PrinterJob $otherJob;
    private string $token;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->store = new Store('print-job-store', 'Print job store');
        $this->other = new Store('other-print-job-store', 'Other print job store');
        $this->user = (new User())->setUsername('cashier-print-jobs')->setEmail('cashier-print-jobs@example.test');
        $permission = new Permission('store:order:print', 'store', 'order', 'print', 'Print orders');
        $role = (new Role('test-print-job-printer', 'Order printer', Role::SCOPE_STORE))->addPermission($permission);
        $account = new PrinterAccount('Test account', 'test', '', 'delicloud');
        $this->job = $this->job(new PrinterDevice($account, 'Receipt', 'STAFF-JOB-1', 'receipt', $this->store->getUuid()));
        $this->job->submitted('provider-job-1');
        $this->otherJob = $this->job(new PrinterDevice($account, 'Receipt', 'STAFF-JOB-2', 'receipt', $this->other->getUuid()));
        $this->otherJob->submitted('provider-job-2');
        foreach ([$this->store, $this->other, $this->user, $permission, $role, $account, $this->job->getDevice(), $this->otherJob->getDevice(),
            $this->job, $this->otherJob,
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_CLERK),
            new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid())] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
    }

    public function testListAndDetailSerializeAllStoreOwnedJobFields(): void
    {
        $response = $this->request('GET', '');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $list = $this->data($response);
        self::assertCount(1, $list);
        self::assertSame($this->job->getUuid(), $list[0]['uuid']);
        self::assertSame($this->job->getDevice()->getUuid(), $list[0]['device']['uuid']);
        self::assertSame($this->job->getRenderedContent(), $list[0]['renderedContent']);

        $detail = $this->data($this->request('GET', '/'.$this->job->getUuid()));
        self::assertSame($this->job->getUuid(), $detail['uuid']);
        self::assertSame($this->job->getDevice()->getUuid(), $detail['device']['uuid']);
        self::assertSame('receipt', $detail['kind']);
        self::assertSame(2, $detail['copies']);
        self::assertSame($this->job->getTemplateSnapshot(), $detail['templateSnapshot']);
        self::assertSame($this->job->getDataSnapshot(), $detail['dataSnapshot']);
        self::assertSame($this->job->getRenderedContent(), $detail['renderedContent']);
        self::assertSame(PrinterJob::STATUS_SUBMITTED, $detail['status']);
        self::assertSame('provider-job-1', $detail['providerOrderId']);
        self::assertSame($this->store->getUuid(), $detail['storeUuid']);
        self::assertSame($this->job->getIdempotencyKey(), $detail['idempotencyKey']);
        self::assertNotEmpty($detail['createdAt']);
    }

    public function testPreviewReturnsTheStoredPrintDocument(): void
    {
        $preview = $this->data($this->request('GET', '/'.$this->job->getUuid().'/preview'));
        self::assertSame(2, $preview['copies']);
        self::assertSame('receipt', $preview['document']['kind']);
        self::assertSame([$this->job->getRenderedContent()], $preview['document']['pages']);
        self::assertSame($this->job->getRenderedContent(), $preview['document']['content']);
        self::assertSame(58, $preview['document']['widthMm']);
        self::assertNull($preview['document']['heightMm']);

        self::assertSame(404, $this->request('GET', '/'.$this->otherJob->getUuid().'/preview')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/'.$this->job->getUuid().'/preview', storeUuid: $this->other->getUuid())->getStatusCode());
    }

    public function testReprintFollowsTheManageLifecycleAndRejectsUnresolvedJobs(): void
    {
        $unresolved = $this->job(new PrinterDevice($this->job->getDevice()->getAccount(), 'Receipt', 'STAFF-JOB-3', 'receipt', $this->store->getUuid()));
        $unresolved->unknown();
        $this->em->persist($unresolved->getDevice());
        $this->em->persist($unresolved);
        $this->em->flush();

        self::assertSame(409, $this->request('POST', '/'.$unresolved->getUuid().'/reprint')->getStatusCode());
        self::assertSame(404, $this->request('POST', '/'.$this->otherJob->getUuid().'/reprint')->getStatusCode());
    }

    public function testReprintCreatesANewJobFromTheStoredSnapshot(): void
    {
        self::getContainer()->set(PrinterProviderRegistry::class, new PrinterProviderRegistry([new FakeStorePrintProvider()]));
        $response = $this->request('POST', '/'.$this->job->getUuid().'/reprint');
        self::assertSame(201, $response->getStatusCode());
        $reprint = $this->data($response);
        self::assertNotSame($this->job->getUuid(), $reprint['uuid']);
        self::assertSame($this->job->getTemplateSnapshot(), $reprint['templateSnapshot']);
        self::assertSame($this->job->getDataSnapshot(), $reprint['dataSnapshot']);
        self::assertSame($this->job->getRenderedContent(), $reprint['renderedContent']);
        self::assertSame(2, $reprint['copies']);
        self::assertSame(PrinterJob::STATUS_SUBMITTED, $reprint['status']);
        self::assertStringStartsWith('store-reprint-', $reprint['providerOrderId']);
    }

    public function testStoreScopeAndPrintPermissionAreRequired(): void
    {
        self::assertSame(401, $this->request('GET', '', authenticated: false)->getStatusCode());
        self::assertSame(403, $this->request('GET', '', storeUuid: $this->other->getUuid())->getStatusCode());
        self::assertSame(403, $this->request('GET', '/'.$this->job->getUuid(), storeUuid: $this->other->getUuid())->getStatusCode());
        self::assertSame(403, $this->request('POST', '/'.$this->job->getUuid().'/reprint', storeUuid: $this->other->getUuid())->getStatusCode());

        $this->em->remove($this->roleAssignments()[0]);
        $this->em->flush();
        self::assertSame(403, $this->request('GET', '')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/'.$this->job->getUuid().'/reprint')->getStatusCode());
    }

    private function job(PrinterDevice $device): PrinterJob
    {
        return new PrinterJob($device, 2,
            ['kind' => 'receipt', 'widthMm' => 58, 'nodes' => [['type' => 'field', 'path' => 'order.number']]],
            ['order' => ['number' => '42']], '<C>Receipt</C>', 'print-job-'.bin2hex(random_bytes(8)));
    }

    /** @return list<Assignment> */
    private function roleAssignments(): array
    {
        return $this->em->getRepository(Assignment::class)->findBy(['userUuid' => $this->user->getUuid()]);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    private function request(string $method, string $path, ?string $storeUuid = null, bool $authenticated = true): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/store/'.($storeUuid ?? $this->store->getUuid()).'/print-jobs'.$path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $authenticated ? 'Bearer '.$this->token : '',
        ]));
    }
}

final class FakeStorePrintProvider implements PrinterProviderInterface
{
    public function code(): string { return 'delicloud'; }

    public function registerDevice(PrinterAccount $account, PrinterDevice $device): array { return ['registeredSerialNumbers' => []]; }

    public function queryDeviceStatus(PrinterDevice $device): array { return ['status' => 0]; }

    public function submitJob(PrinterJob $job): array { return ['providerOrderId' => 'store-reprint-'.$job->getUuid()]; }

    public function queryJobStatus(PrinterJob $job): array { return ['printed' => false]; }
}
