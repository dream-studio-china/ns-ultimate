<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Payment\Contract\PaymentCloseGatewayInterface;
use NsUltimate\Business\Payment\DTO\PaymentCloseResult;
use NsUltimate\Business\Payment\Service\PaymentCloseGatewayRegistry;
use NsUltimate\Business\Store\Entity\StaffOperation;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderPaymentCloseApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Order $order;
    private Invoice $invoice;
    private User $user;
    private string $token;
    private RecordingCloseGateway $gateway;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->store = new Store('payment-close-store', 'Payment close store');
        $this->user = (new User())->setUsername('payment-closer')->setEmail('closer@example.test');
        $role = new Role('payment-close-test', 'Close payments', Role::SCOPE_STORE);
        $permission = new Permission('store:order:payment_close', 'store', 'order', 'payment_close', 'Close payments');
        $role->addPermission($permission);
        $this->order = (new Order())->setUser($this->user)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($this->user)->setStatus(Invoice::STATUS_PAYING)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setPayment('example-pay');
        $this->order->setInvoiceId($this->invoice->getUuid())->setInvoiceNo($this->invoice->getOutTradeNo())->setPaymentStatus('pending')->setPaymentMethod('example-pay');
        foreach ([$this->store, $this->user, $role, $permission, $this->order, $this->invoice,
            new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid()),
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
        $this->gateway = new RecordingCloseGateway();
        self::getContainer()->set(PaymentCloseGatewayRegistry::class, new PaymentCloseGatewayRegistry([$this->gateway]));
    }

    public function testChannelIndependentClosureReleasesWithoutDeletingHistoryAndReplays(): void
    {
        $body = $this->body();
        $response = $this->request($body);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = $this->data($response);
        self::assertSame('closed', $data['status']);
        self::assertTrue($data['released']);
        self::assertSame(Invoice::STATUS_CANCELLED, $this->invoice->getStatus());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order->getStatus());
        self::assertNull($this->order->getInvoiceId());
        self::assertNull($this->order->getPaymentStatus());
        self::assertNull($this->order->getInvoiceNo());
        self::assertNull($this->order->getPaymentMethod());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));
        self::assertSame($data, $this->data($this->request($body)));
        self::assertSame(1, $this->gateway->calls);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(409, $this->request(array_replace($body, ['reason' => 'Changed reason']))->getStatusCode());
    }

    public function testUnconfirmedClosureDoesNotReleaseAndSameCommandCanRetry(): void
    {
        $this->gateway->result = new PaymentCloseResult();
        $body = $this->body();
        $response = $this->request($body);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('payment_close_unconfirmed', $response->getContent());
        $this->assertStillHeld();
        $this->gateway->result = new PaymentCloseResult(closed: true);
        self::assertSame(200, $this->request($body)->getStatusCode());
        self::assertSame(2, $this->gateway->calls);
    }

    public function testTransportFailureDoesNotReleaseOrExposeProviderError(): void
    {
        $this->gateway->error = new \RuntimeException('Sensitive provider details');
        $response = $this->request($this->body());
        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('Sensitive', $response->getContent());
        $this->assertStillHeld();
    }

    public function testLocalFailureAfterChannelConfirmationRollsBackReleaseAndReceipt(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(InvoiceCancelledEvent::class, static function (): void {
            throw new \RuntimeException('Simulated local cancellation failure');
        });
        self::assertSame(502, $this->request($this->body())->getStatusCode());
        $this->assertStillHeld();
    }

    public function testOldCommandReplayCannotCloseANewerInvoice(): void
    {
        $body = $this->body();
        self::assertSame(200, $this->request($body)->getStatusCode());
        $next = (new Invoice())->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setAmount(1000)->setCurrency('CNY')->setStatus(Invoice::STATUS_PAYING)->setPayment('example-pay');
        $this->em->persist($next);
        $this->order->setInvoiceId($next->getUuid())->setPaymentStatus('paying'); $this->em->flush();
        self::assertSame(200, $this->request($body)->getStatusCode());
        self::assertSame($next->getUuid(), $this->order->getInvoiceId());
        self::assertSame(Invoice::STATUS_PAYING, $next->getStatus());
        self::assertSame(1, $this->gateway->calls);
        self::assertSame(409, $this->request(array_replace($body, ['commandUuid' => UUID::v4()]))->getStatusCode());
        self::assertSame(1, $this->gateway->calls);
        self::assertSame(200, $this->request(array_replace($body, ['commandUuid' => UUID::v4(), 'invoiceUuid' => $next->getUuid()]))->getStatusCode());
        self::assertSame(Invoice::STATUS_CANCELLED, $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $next->getUuid()])->getStatus());
        self::assertSame(2, $this->gateway->calls);
    }

    public function testUnsupportedChannelCannotBeLocallyCancelled(): void
    {
        $this->invoice->setPayment('unsupported'); $this->em->flush();
        self::assertSame(409, $this->request($this->body())->getStatusCode());
        self::assertSame(0, $this->gateway->calls);
        $this->assertStillHeld();
    }

    public function testCollectionDuringCloseIsAppliedAndNeverReleased(): void
    {
        $this->gateway->result = new PaymentCloseResult(payment: new PaymentNotifyResult(
            payment: 'example-pay', outTradeNo: $this->invoice->getOutTradeNo(), status: Invoice::STATUS_PAID,
            amount: 1000, currency: 'CNY', transactionId: 'race-collection',
        ));
        $body = $this->body();
        $response = $this->request($body);
        self::assertSame(409, $response->getStatusCode(), $response->getContent());
        self::assertSame('paid', $this->data($response)['status']);
        self::assertFalse($this->data($response)['released']);
        self::assertSame(Invoice::STATUS_PAID, $this->invoice->getStatus());
        self::assertSame(Order::STATUS_PAID, $this->order->getStatus());
        self::assertSame($this->invoice->getUuid(), $this->order->getInvoiceId());
        self::assertSame(409, $this->request($body)->getStatusCode());
        self::assertSame(1, $this->gateway->calls);
    }

    public function testCollectionOnFailedLocalInvoiceIsPreservedForReviewWithoutRelease(): void
    {
        $this->invoice->setStatus(Invoice::STATUS_FAILED); $this->em->flush();
        $this->gateway->result = new PaymentCloseResult(payment: new PaymentNotifyResult(
            payment: 'example-pay', outTradeNo: $this->invoice->getOutTradeNo(), status: Invoice::STATUS_PAID,
            amount: 1000, currency: 'CNY', transactionId: 'unexpected-collection',
        ));
        $response = $this->request($this->body());
        self::assertSame(409, $response->getStatusCode());
        self::assertTrue($this->data($response)['requiresReview']);
        self::assertFalse($this->data($response)['released']);
        self::assertSame($this->invoice->getUuid(), $this->order->getInvoiceId());
        self::assertSame(Invoice::STATUS_FAILED, $this->invoice->getStatus());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order->getStatus());
        self::assertSame('unexpected-collection', $this->invoice->getExtraData()['payment_close_collection']['transactionId']);
    }

    public function testExpectedInvoiceAndOtherHistoricalInvoicesFailBeforeProvider(): void
    {
        self::assertSame(409, $this->request(array_replace($this->body(), ['invoiceUuid' => UUID::v4()]))->getStatusCode());
        $old = (new Invoice())->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setAmount(1000)->setCurrency('CNY');
        $this->em->persist($old); $this->em->flush();
        self::assertSame(409, $this->request($this->body())->getStatusCode());
        self::assertSame(0, $this->gateway->calls);
        $this->assertStillHeld();
    }

    public function testAdminEndpointDoesNotRequireStoreMembershipButStoreEndpointDoes(): void
    {
        $admin = (new User())->setUsername('close-admin')->setEmail('close-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin); $this->em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        self::assertSame(403, $this->request($this->body(), token: $token)->getStatusCode());
        self::assertSame(200, $this->request($this->body(), admin: true, token: $token)->getStatusCode());
    }

    public function testOrdinaryStaffCannotUseAdminEndpointAndCrossStoreCannotClose(): void
    {
        self::assertSame(403, $this->request($this->body(), admin: true)->getStatusCode());
        $other = new Store('other-close-store', 'Other close store');
        $this->em->persist($other); $this->em->flush();
        $scope = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $this->order->getUuid()]);
        $this->em->remove($scope); $this->em->flush();
        $this->em->persist(new OrderStoreLifecycle($this->order->getUuid(), $other->getUuid())); $this->em->flush();
        self::assertSame(404, $this->request($this->body())->getStatusCode());
        self::assertSame(0, $this->gateway->calls);
        $this->assertStillHeld();
    }

    public function testMissingPermissionAndInvalidInputCannotCallProvider(): void
    {
        foreach ([['commandUuid' => UUID::v4()], array_replace($this->body(), ['amount' => 1]), array_replace($this->body(), ['reason' => ''])] as $body) {
            self::assertSame(400, $this->request($body)->getStatusCode());
        }
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request($this->body())->getStatusCode());
        self::assertSame(0, $this->gateway->calls);
    }

    /** @return array{commandUuid: string, invoiceUuid: string, reason: string} */
    private function body(): array { return ['commandUuid' => UUID::v4(), 'invoiceUuid' => $this->invoice->getUuid(), 'reason' => 'Customer requests payment closure']; }

    /** @param array<string, mixed> $body */
    private function request(array $body, bool $admin = false, ?string $token = null): Response
    {
        $url = $admin ? '/api/v1/manage/orders/'.$this->order->getUuid().'/payment/close'
            : '/api/v1/store/'.$this->store->getUuid().'/trade-orders/'.$this->order->getUuid().'/payment/close';
        return self::$kernel->handle(Request::create($url, 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token), 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']; }

    private function assertStillHeld(): void
    {
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]);
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $this->invoice->getUuid()]);
        self::assertSame($invoice->getUuid(), $order->getInvoiceId());
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
        self::assertSame(0, $this->em->getRepository(StaffOperation::class)->count([]));
    }
}

final class RecordingCloseGateway implements PaymentCloseGatewayInterface
{
    public int $calls = 0;
    public PaymentCloseResult $result;
    public ?\Throwable $error = null;
    public function __construct() { $this->result = new PaymentCloseResult(closed: true); }
    public static function payment(): string { return 'example-pay'; }
    public function close(Invoice $invoice): PaymentCloseResult
    {
        ++$this->calls;
        if ($this->error !== null) throw $this->error;
        return $this->result;
    }
}
