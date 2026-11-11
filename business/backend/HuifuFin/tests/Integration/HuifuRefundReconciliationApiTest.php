<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../Support/FakeHuifuClient.php';

final class HuifuRefundReconciliationApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakeHuifuClient $client;
    private Store $store;
    private Store $otherStore;
    private Invoice $invoice;
    private HuifuRefund $refund;
    private string $adminToken;
    private string $memberToken;
    private string $viewerToken;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->client = new FakeHuifuClient($privateKey);
        self::getContainer()->set(HuifuClientInterface::class, $this->client);

        $admin = (new User())->setUsername('refund-reconcile-admin')->setEmail('refund-reconcile-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $member = (new User())->setUsername('refund-reconcile-member')->setEmail('refund-reconcile-member@example.test');
        $viewer = (new User())->setUsername('refund-reconcile-viewer')->setEmail('refund-reconcile-viewer@example.test');
        $this->store = new Store('refund-reconcile-store', 'Refund reconciliation store');
        $this->otherStore = new Store('refund-reconcile-other', 'Other refund reconciliation store');
        $role = new Role('refund-reconcile-test', 'Refund reconciliation test', Role::SCOPE_STORE);
        $permission = new Permission('store:order:refund', 'store', 'order', 'refund', 'Refund order');
        $role->addPermission($permission);

        $provider = new HuifuProviderProfile('Refund API provider', 'production', 'test-system', 'test-product');
        $provider->setCredentials(['merchant_private_key' => $privateKey, 'huifu_public_key' => $details['key']], self::getContainer()->get(HuifuSecretCipher::class));
        $merchant = new HuifuMerchant('Refund API merchant', 'test-merchant', $provider, ['native']);
        $order = (new Order())->setUser($member)->setStatus(Order::STATUS_PAID)->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($member)->setStatus(Invoice::STATUS_PAID)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($order->getUuid())->setScene(Invoice::SCENE_ORDER)->setPayment('huifu');
        $order->setInvoiceId($this->invoice->getUuid());
        $attempt = new HuifuPaymentAttempt(
            $this->invoice,
            $this->store->getUuid(),
            $provider->getUuid(),
            $merchant->getUuid(),
            $merchant->getHuifuId(),
            $provider->getCredentialVersion(),
            '20261105',
            'REFUND-API-PAYMENT',
            'native',
            'T_NATIVE',
            1000,
        );
        $attempt->markSucceeded('refund-api-transaction');

        foreach ([
            $admin,
            $member,
            $viewer,
            $this->store,
            $this->otherStore,
            $role,
            $permission,
            $provider,
            $merchant,
            $order,
            $this->invoice,
            $attempt,
            new Membership($this->store, $member->getUuid(), Membership::ROLE_MANAGER),
            new Membership($this->otherStore, $member->getUuid(), Membership::ROLE_MANAGER),
            new Membership($this->store, $viewer->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $member->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid()),
            new Assignment($role, $member->getUuid(), Assignment::SCOPE_STORE, $this->otherStore->getUuid()),
        ] as $entity) {
            $this->em->persist($entity);
        }
        $this->refund = new HuifuRefund($this->invoice, $attempt, 'REFUND-API-SEQUENCE', '20261105', 400, 400);
        $this->refund->markUnknown();
        $this->em->persist($this->refund);
        $this->em->flush();

        $tokens = self::getContainer()->get(TokenManager::class);
        $this->adminToken = $tokens->createAccessToken($admin);
        $this->memberToken = $tokens->createAccessToken($member);
        $this->viewerToken = $tokens->createAccessToken($viewer);
    }

    public function testManageEndpointReconcilesByHuifuRefundUuidWithoutStoreMembership(): void
    {
        $this->client->responses[] = ['data' => $this->response()];
        $response = $this->request(
            'POST',
            '/api/v1/manage/huifu-refunds/'.strtoupper($this->refund->getUuid()).'/reconcile',
            $this->adminToken,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertSame($this->refund->getUuid(), $data['uuid']);
        self::assertSame($this->invoice->getUuid(), $data['invoiceUuid']);
        self::assertSame($this->store->getUuid(), $data['storeUuid']);
        self::assertSame(HuifuRefund::STATUS_SUCCEEDED, $data['status']);
        self::assertTrue($data['coreApplied']);
        self::assertSame(400, $data['confirmedAmount']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach (['requestSnapshot', 'responseSnapshot', 'responseMessage', 'responseCode', 'refundNo'] as $privateField) {
            self::assertArrayNotHasKey($privateField, $data);
        }

        self::assertCount(1, $this->client->requests);
        self::assertSame(HuifuEndpoints::SCANPAY_REFUND_QUERY, $this->client->requests[0]['path']);
        $this->em->refresh($this->invoice);
        self::assertSame(400, $this->invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $this->invoice->getStatus());

        $replay = $this->request('POST', '/api/v1/manage/huifu-refunds/'.$this->refund->getUuid().'/reconcile', $this->adminToken);
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getContent());
        self::assertCount(1, $this->client->requests);
    }

    public function testStoreEndpointRequiresRefundPermissionAndRefundOwnership(): void
    {
        $this->client->responses[] = ['data' => $this->response()];
        $path = '/api/v1/store/'.$this->store->getUuid().'/huifu-refunds/'.$this->refund->getUuid().'/reconcile';
        $response = $this->request('POST', $path, $this->memberToken);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->data($response)['coreApplied']);

        self::assertSame(200, $this->request('POST', $path, $this->adminToken)->getStatusCode());
        self::assertSame(403, $this->request('POST', $path, $this->viewerToken)->getStatusCode());
        $crossStorePath = '/api/v1/store/'.$this->otherStore->getUuid().'/huifu-refunds/'.$this->refund->getUuid().'/reconcile';
        self::assertSame(404, $this->request('POST', $crossStorePath, $this->memberToken)->getStatusCode());
        self::assertCount(1, $this->client->requests);
    }

    public function testPersistedSuccessfulRefundIsReappliedWithoutAnotherProviderQuery(): void
    {
        $this->refund->markSucceeded(400);
        $this->em->flush();

        $response = $this->request('POST', '/api/v1/manage/huifu-refunds/'.$this->refund->getUuid().'/reconcile', $this->adminToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->data($response)['coreApplied']);
        self::assertCount(0, $this->client->requests);
        $this->em->refresh($this->invoice);
        self::assertSame(400, $this->invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $this->invoice->getStatus());
    }

    public function testUnavailableAndIneligibleRefundsDoNotContactTheProvider(): void
    {
        self::assertSame(
            404,
            $this->request('POST', '/api/v1/manage/huifu-refunds/'.UUID::v4().'/reconcile', $this->adminToken)->getStatusCode(),
        );

        $created = new HuifuRefund($this->invoice, $this->refund->getAttempt(), 'REFUND-API-CREATED', '20261105', 100, 100);
        $this->em->persist($created);
        $this->em->flush();
        self::assertSame(
            409,
            $this->request('POST', '/api/v1/manage/huifu-refunds/'.$created->getUuid().'/reconcile', $this->adminToken)->getStatusCode(),
        );
        self::assertCount(0, $this->client->requests);
    }

    public function testProviderFailureReturnsUnknownStateWithoutPrivateDiagnostics(): void
    {
        $this->client->responses[] = new \RuntimeException('Private provider transport diagnostics');
        $response = $this->request('POST', '/api/v1/manage/huifu-refunds/'.$this->refund->getUuid().'/reconcile', $this->adminToken);

        self::assertSame(502, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('huifu_reconciliation_failed', json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['message']);
        self::assertSame(HuifuRefund::STATUS_UNKNOWN, $this->data($response)['status']);
        self::assertFalse($this->data($response)['coreApplied']);
        self::assertStringNotContainsString('Private provider transport diagnostics', (string) $response->getContent());
        self::assertCount(1, $this->client->requests);
    }

    private function request(string $method, string $path, string $token): Response
    {
        return self::$kernel->handle(Request::create(
            $path,
            $method,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
        ));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'resp_code' => '00000000',
            'resp_desc' => 'Confirmed refund',
            'trans_stat' => 'S',
            'req_date' => $this->refund->getReqDate(),
            'req_seq_id' => $this->refund->getRefundNo(),
            'huifu_id' => 'test-merchant',
            'refund_amt' => '4.00',
        ];
    }
}
