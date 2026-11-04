<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Service\OrderInvoiceSourcePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Entity\HuifuWebhookEvent;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuOperationProof;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuNotificationService;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentGateway;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentService;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuReconciliationService;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuRequestFactory;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuStoreContextResolver;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__.'/../Support/FakeHuifuClient.php';

final class HuifuPaymentFlowTest extends KernelTestCase
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

    public function testPaymentAndRefundRequireProviderConfirmation(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(HuifuSecretCipher::class);
        $user = (new User())->setEmail('huifu-flow@example.test')->setUsername('huifu-flow');
        $store = new Store('huifu-flow-store', 'Huifu Flow Store');
        $em->persist($user);
        $em->persist($store);
        $em->flush();

        $provider = new HuifuProviderProfile('Flow provider', 'production', '6666000000000001', 'YMFZS');
        $provider->setCredentials([
            'merchant_private_key' => $privateKey,
            'huifu_public_key' => $publicKey,
            'async_public_key' => $publicKey,
        ], $cipher);
        $merchant = new HuifuMerchant('Flow merchant', '6666000000000101', $provider);
        $merchant->setCapabilities(['native']);
        $setting = new StoreHuifuSetting($store->getUuid(), $merchant);
        $setting->setAllowedMethods(['native']);
        $setting->setDefaultMethod('native');
        foreach ([$provider, $merchant, $setting] as $entity) {
            $em->persist($entity);
        }
        $order = (new Order())->setUser($user)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1000)->setCurrency('CNY');
        $em->persist($order);
        $orderUuid = $order->getUuid();
        $em->persist(new OrderStoreLifecycle($orderUuid, $store->getUuid()));
        $em->flush();

        $invoiceService = self::getContainer()->get(InvoiceServiceInterface::class);
        self::assertInstanceOf(
            HuifuPaymentGateway::class,
            self::getContainer()->get(PaymentGatewayRegistry::class)->get('huifu'),
        );
        $invoice = $invoiceService->createInvoice(new CreateInvoiceRequest(
            sourceType: 'trade_order',
            sourceId: $orderUuid,
            scene: Invoice::SCENE_ORDER,
            amount: 1000,
            currency: 'CNY',
            payer: $user,
            subject: 'Huifu flow order',
        ));
        $attempt = new HuifuPaymentAttempt(
            invoice: $invoice,
            storeUuid: $store->getUuid(),
            providerProfileUuid: $provider->getUuid(),
            merchantUuid: $merchant->getUuid(),
            huifuId: $merchant->getHuifuId(),
            credentialVersion: $provider->getCredentialVersion(),
            reqDate: '20261101',
            reqSeqId: 'HFTEST0000000001',
            channel: 'native',
            tradeType: 'T_NATIVE',
            amount: 1000,
        );
        $em->persist($attempt);
        $em->flush();

        $client = new FakeHuifuClient($privateKey);
        $client->responses[] = ['data' => [
            'resp_code' => '00000100',
            'resp_desc' => '下单成功',
            'req_seq_id' => $attempt->getReqSeqId(),
            'req_date' => '20261101',
            'huifu_id' => $merchant->getHuifuId(),
            'trans_amt' => '10.00',
            'trans_stat' => 'P',
            'hf_seq_id' => 'HFSEQ0001',
            'qr_code' => 'https://qr.example.test/order',
        ]];
        $resolver = self::getContainer()->get(HuifuProfileResolver::class);
        $signer = self::getContainer()->get(HuifuSigner::class);
        $parser = self::getContainer()->get(HuifuResponseParser::class);
        $proof = self::getContainer()->get(HuifuOperationProof::class);
        $gateway = new HuifuPaymentGateway(
            $em,
            $resolver,
            self::getContainer()->get(HuifuRequestFactory::class),
            $client,
            $parser,
            $signer,
            $proof,
        );
        $result = $gateway->pay($invoice, 1000, [
            'huifuAttemptUuid' => $attempt->getUuid(),
            'huifuAttemptProof' => $proof->payment($attempt->getUuid()),
        ]);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('https://qr.example.test/order', $result->qrCode);
        self::assertSame(HuifuPaymentAttempt::STATUS_PENDING, $attempt->getStatus());
        self::assertCount(1, $client->requests);

        try {
            $gateway->pay($invoice, 1000, ['huifuAttemptUuid' => $attempt->getUuid()]);
            self::fail('The generic pay route must not submit a Huifu attempt without the workflow proof.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Store payment endpoint', $exception->getMessage());
        }
        try {
            $gateway->pay($invoice, 1000, [
                'huifuAttemptUuid' => $attempt->getUuid(),
                'huifuAttemptProof' => $proof->payment($attempt->getUuid()),
            ]);
            self::fail('A submitted Huifu attempt must not be submitted again.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already been submitted', $exception->getMessage());
        }

        $notification = new HuifuNotificationService(
            $em,
            $resolver,
            $signer,
            $parser,
            $invoiceService,
        );
        // A delivery recorded by an interrupted attempt must still be applied
        // instead of being acknowledged as if it had already completed.
        $em->persist(new HuifuWebhookEvent(
            eventKey: sprintf('payment:%s:%s:%s:%s', $attempt->getUuid(), $attempt->getReqSeqId(), 'S', 'WX-TRANSACTION-1'),
            type: 'payment',
            attempt: $attempt,
            bodyHash: hash('sha256', 'interrupted-delivery'),
        ));
        $em->flush();
        $respData = json_encode([
            'resp_code' => '00000000',
            'resp_desc' => '交易成功',
            'req_seq_id' => $attempt->getReqSeqId(),
            'req_date' => '20261101',
            'huifu_id' => $merchant->getHuifuId(),
            'trans_amt' => '10.00',
            'trans_stat' => 'S',
            'out_trans_id' => 'WX-TRANSACTION-1',
            'end_time' => '20261101120000',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $sign = $this->signRaw($respData, $privateKey);
        $request = Request::create(
            '/api/payment/notify/huifu/'.$attempt->getUuid(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['resp_code' => '00000000', 'resp_data' => $respData, 'sign' => $sign]),
        );
        self::assertSame('RECV_ORD_ID_'.$attempt->getReqSeqId(), $notification->handle($request, $attempt->getUuid()));
        $em->refresh($invoice);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame('huifu', $invoice->getPayment());
        self::assertSame('WX-TRANSACTION-1', $invoice->getTransactionId());
        $em->refresh($attempt);
        self::assertSame(HuifuPaymentAttempt::STATUS_SUCCEEDED, $attempt->getStatus());

        self::assertSame('RECV_ORD_ID_'.$attempt->getReqSeqId(), $notification->handle($request, $attempt->getUuid()));
        self::assertSame(1, $em->getRepository(HuifuWebhookEvent::class)->count([]));

        $jsonRequest = Request::create(
            '/api/payment/notify/huifu/'.$attempt->getUuid(),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'resp_code' => '00000000',
                'resp_data' => $respData,
                'sign' => $sign,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame('RECV_ORD_ID_'.$attempt->getReqSeqId(), $notification->handle($jsonRequest, $attempt->getUuid()));

        $lateFailure = json_decode($respData, true, flags: JSON_THROW_ON_ERROR);
        $lateFailure['resp_desc'] = '交易失败';
        $lateFailure['trans_stat'] = 'F';
        $lateFailure['out_trans_id'] = 'WX-TRANSACTION-LATE-FAILURE';
        $lateFailureBody = json_encode($lateFailure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lateFailureRequest = Request::create(
            '/api/payment/notify/huifu/'.$attempt->getUuid(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'resp_code' => '00000000',
                'resp_data' => $lateFailureBody,
                'sign' => $this->signRaw($lateFailureBody, $privateKey),
            ]),
        );
        $notification->handle($lateFailureRequest, $attempt->getUuid());
        $em->refresh($attempt);
        $em->refresh($invoice);
        self::assertSame(HuifuPaymentAttempt::STATUS_SUCCEEDED, $attempt->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        $events = $em->getRepository(HuifuWebhookEvent::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $events);
        self::assertNotNull($events[1]->getError());

        $refundClient = new FakeHuifuClient($privateKey);
        $refundClient->responses[] = ['data' => [
            'resp_code' => '00000000',
            'resp_desc' => '退款成功',
            'trans_stat' => 'S',
            'req_seq_id' => 'HFRTEST',
        ]];
        $refunds = new HuifuRefundWorkflow(
            $em,
            $resolver,
            $signer,
            $refundClient,
            $parser,
            $invoiceService,
            self::getContainer()->get(PaymentAdjustmentRegistry::class),
            $proof,
            [self::getContainer()->get(OrderInvoiceSourcePolicy::class)],
        );
        $refund = $refunds->request($invoice, 400, 'Test refund');
        self::assertSame(HuifuRefund::STATUS_SUCCEEDED, $refund->getStatus());
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(400, $refund->getProviderAmount());
        self::assertSame(400, $refund->getConfirmedAmount());
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $invoice->getStatus());

        try {
            $gateway->refund($invoice, 400, 1000, 'Replay the same Huifu refund', ['huifuRefundUuid' => $refund->getUuid()]);
            self::fail('A confirmed Huifu refund must not be applied twice.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already been applied', $exception->getMessage());
        }
        $refunds->applyToCore($refund);
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());

        try {
            $refunds->request($invoice, 700, 'Exceeds the remaining amount');
            self::fail('A refund above the remaining invoice amount must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('exceeds the remaining', $exception->getMessage());
        }

        $pendingClient = new FakeHuifuClient($privateKey);
        $pendingClient->responses[] = ['data' => [
            'resp_code' => '00000000',
            'resp_desc' => '退款处理中',
            'trans_stat' => 'P',
            'req_seq_id' => 'HFRPENDING',
        ]];
        $pendingClient->responses[] = ['data' => [
            'resp_code' => '00000000',
            'resp_desc' => '退款处理中',
            'trans_stat' => 'P',
            'req_seq_id' => 'HFRPENDING',
        ]];
        $pendingRefunds = new HuifuRefundWorkflow(
            $em,
            $resolver,
            $signer,
            $pendingClient,
            $parser,
            $invoiceService,
            self::getContainer()->get(PaymentAdjustmentRegistry::class),
            $proof,
            [self::getContainer()->get(OrderInvoiceSourcePolicy::class)],
        );
        $pending = $pendingRefunds->request($invoice, 600, 'Pending refund');
        self::assertSame(HuifuRefund::STATUS_PROCESSING, $pending->getStatus());
        self::assertFalse($pending->isCoreApplied());
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $invoice->getStatus());
        try {
            $pendingRefunds->request($invoice, 100, 'Conflicts with the reserved amount');
            self::fail('A refund above the unreserved remaining amount must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('already using the remaining', $exception->getMessage());
        }

        $pending->markSucceeded(600);
        $em->flush();
        try {
            $gateway->refund($invoice, 600, 1000, 'Apply without the HuifuFin proof', ['huifuRefundUuid' => $pending->getUuid()]);
            self::fail('The gateway must not consume an unapplied Huifu refund without the workflow proof.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refund workflow', $exception->getMessage());
        }
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());
        $reconciliation = new HuifuReconciliationService(
            $em,
            $resolver,
            $signer,
            new FakeHuifuClient($privateKey),
            $parser,
            $invoiceService,
            $pendingRefunds,
        );
        $due = array_map(static fn (HuifuRefund $dueRefund): string => $dueRefund->getUuid(), $reconciliation->dueRefunds(10));
        self::assertContains($pending->getUuid(), $due);
        $reconciliation->queryRefund($pending);
        $em->refresh($pending);
        $em->refresh($invoice);
        self::assertTrue($pending->isCoreApplied());
        self::assertSame(1000, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());

        $stale = new HuifuPaymentAttempt(
            invoice: $invoice,
            storeUuid: $store->getUuid(),
            providerProfileUuid: $provider->getUuid(),
            merchantUuid: $merchant->getUuid(),
            huifuId: $merchant->getHuifuId(),
            credentialVersion: $provider->getCredentialVersion(),
            reqDate: '20261101',
            reqSeqId: 'HFSTALECREATED0001',
            channel: 'native',
            tradeType: 'T_NATIVE',
            amount: 100,
        );
        $fresh = new HuifuPaymentAttempt(
            invoice: $invoice,
            storeUuid: $store->getUuid(),
            providerProfileUuid: $provider->getUuid(),
            merchantUuid: $merchant->getUuid(),
            huifuId: $merchant->getHuifuId(),
            credentialVersion: $provider->getCredentialVersion(),
            reqDate: '20261101',
            reqSeqId: 'HFFRESHCREATED0001',
            channel: 'native',
            tradeType: 'T_NATIVE',
            amount: 100,
        );
        $em->persist($stale);
        $em->persist($fresh);
        $em->flush();
        $em->getConnection()->executeStatement(
            'UPDATE huifu_payment_attempt SET created_at = :created WHERE id = :id',
            [
                'created' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'),
                'id' => $stale->getId(),
            ],
        );
        $dueAttempts = array_map(
            static fn (HuifuPaymentAttempt $dueAttempt): string => $dueAttempt->getUuid(),
            $reconciliation->duePayments(10),
        );
        self::assertContains($stale->getUuid(), $dueAttempts);
        self::assertNotContains($fresh->getUuid(), $dueAttempts);
    }

    public function testStorePaymentServiceCreatesAttemptBeforeCallingCore(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(HuifuSecretCipher::class);
        $user = (new User())->setEmail('huifu-init@example.test')->setUsername('huifu-init');
        $store = new Store('huifu-init-store', 'Huifu Init Store');
        $em->persist($user);
        $em->persist($store);
        $em->flush();
        $provider = new HuifuProviderProfile('Init provider', 'production', '6666000000000001', 'YMFZS');
        $provider->setCredentials([
            'merchant_private_key' => $privateKey,
            'huifu_public_key' => $publicKey,
        ], $cipher);
        $merchant = new HuifuMerchant('Init merchant', '6666000000000101', $provider);
        $merchant->setCapabilities(['native']);
        $setting = new StoreHuifuSetting($store->getUuid(), $merchant);
        $setting->setAllowedMethods(['native']);
        $setting->setDefaultMethod('native');
        foreach ([$provider, $merchant, $setting] as $entity) {
            $em->persist($entity);
        }
        $orderUuid = 'order-huifu-init';
        $em->persist(new OrderStoreLifecycle($orderUuid, $store->getUuid()));
        $invoice = new Invoice();
        $invoice->setSourceType('trade_order')->setSourceId($orderUuid)->setScene(Invoice::SCENE_ORDER)
            ->setAmount(1200)->setCurrency('CNY')->setPayer($user)->setSubject('Init order');
        $em->persist($invoice);
        $em->flush();

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('pay')->willReturnCallback(
            static function (Invoice $paidInvoice, string $payment, array $options): \App\Payment\DTO\PaymentResult {
                self::assertSame('huifu', $payment);
                self::assertSame('native', $options['gateway']);
                self::assertSame('T_NATIVE', $options['tradeType']);
                self::assertArrayHasKey('huifuAttemptUuid', $options);
                self::assertSame(Invoice::STATUS_PENDING, $paidInvoice->getStatus());

                return new \App\Payment\DTO\PaymentResult($paidInvoice, Invoice::STATUS_PAYING);
            },
        );
        $service = new HuifuPaymentService(
            $em,
            self::getContainer()->get(HuifuProfileResolver::class),
            self::getContainer()->get(HuifuStoreContextResolver::class),
            $invoiceService,
            self::getContainer()->get(HuifuOperationProof::class),
        );
        $result = $service->start($invoice, 'native');
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        $attempt = $em->getRepository(HuifuPaymentAttempt::class)->findOneBy(['invoice' => $invoice]);
        self::assertInstanceOf(HuifuPaymentAttempt::class, $attempt);
        self::assertSame($store->getUuid(), $attempt->getStoreUuid());
        self::assertSame(HuifuPaymentAttempt::STATUS_CLOSED, $attempt->getStatus());
        self::assertSame(1200, $attempt->getAmount());
    }

    public function testTimeoutMarksTheAttemptUnknownAndBlocksReplacement(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(HuifuSecretCipher::class);
        $user = (new User())->setEmail('huifu-timeout@example.test')->setUsername('huifu-timeout');
        $store = new Store('huifu-timeout-store', 'Huifu Timeout Store');
        $em->persist($user);
        $em->persist($store);
        $em->flush();
        $provider = new HuifuProviderProfile('Timeout provider', 'production', '6666000000000001', 'YMFZS');
        $provider->setCredentials([
            'merchant_private_key' => $privateKey,
            'huifu_public_key' => $publicKey,
        ], $cipher);
        $merchant = new HuifuMerchant('Timeout merchant', '6666000000000101', $provider);
        $merchant->setCapabilities(['native']);
        $setting = new StoreHuifuSetting($store->getUuid(), $merchant);
        $setting->setAllowedMethods(['native']);
        $setting->setDefaultMethod('native');
        $orderUuid = 'order-huifu-timeout';
        $em->persist($provider);
        $em->persist($merchant);
        $em->persist($setting);
        $em->persist(new OrderStoreLifecycle($orderUuid, $store->getUuid()));
        $invoice = (new Invoice())
            ->setSourceType('trade_order')
            ->setSourceId($orderUuid)
            ->setScene(Invoice::SCENE_ORDER)
            ->setAmount(900)
            ->setCurrency('CNY')
            ->setPayer($user)
            ->setSubject('Timeout order');
        $em->persist($invoice);
        $em->flush();

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('pay')->willReturnCallback(
            static function (Invoice $paidInvoice, string $payment, array $options) use ($em): never {
                $attempt = $em->getRepository(HuifuPaymentAttempt::class)
                    ->findOneBy(['uuid' => $options['huifuAttemptUuid']]);
                self::assertInstanceOf(HuifuPaymentAttempt::class, $attempt);
                $connection = $em->getConnection();
                $connection->beginTransaction();
                $attempt->markSubmitted();
                $attempt->markUnknown();
                $em->flush();
                $connection->rollBack();
                throw new \RuntimeException('simulated provider timeout');
            },
        );
        $service = new HuifuPaymentService(
            $em,
            self::getContainer()->get(HuifuProfileResolver::class),
            self::getContainer()->get(HuifuStoreContextResolver::class),
            $invoiceService,
            self::getContainer()->get(HuifuOperationProof::class),
        );

        try {
            $service->start($invoice, 'native');
            self::fail('The simulated provider timeout must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated provider timeout', $exception->getMessage());
        }

        $attempt = $em->getRepository(HuifuPaymentAttempt::class)->findOneBy(['invoice' => $invoice]);
        self::assertInstanceOf(HuifuPaymentAttempt::class, $attempt);
        $em->refresh($attempt);
        self::assertSame(HuifuPaymentAttempt::STATUS_UNKNOWN, $attempt->getStatus());

        try {
            $service->start($invoice, 'native');
            self::fail('A replacement attempt must wait for provider confirmation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('requires reconciliation', $exception->getMessage());
        }
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    private function signRaw(string $data, string $privateKey): string
    {
        $resource = openssl_pkey_get_private($privateKey);
        self::assertNotFalse($resource);
        $signature = '';
        self::assertTrue(openssl_sign($data, $signature, $resource, OPENSSL_ALGO_SHA256));

        return base64_encode($signature);
    }
}
