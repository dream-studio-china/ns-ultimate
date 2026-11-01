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
use App\Trade\Entity\OrderStoreLifecycle;
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
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuNotificationService;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentGateway;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentService;
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
        $orderUuid = 'order-huifu-flow';
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

        $client = new FakeHuifuClient();
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
        $gateway = new HuifuPaymentGateway(
            $em,
            $resolver,
            self::getContainer()->get(HuifuRequestFactory::class),
            $client,
            $parser,
            $signer,
        );
        $result = $gateway->pay($invoice, 1000, ['huifuAttemptUuid' => $attempt->getUuid()]);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('https://qr.example.test/order', $result->qrCode);
        self::assertSame(HuifuPaymentAttempt::STATUS_PENDING, $attempt->getStatus());
        self::assertCount(1, $client->requests);

        $notification = new HuifuNotificationService(
            $em,
            $resolver,
            $signer,
            $parser,
            $invoiceService,
        );
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

        $refundClient = new FakeHuifuClient();
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
        );
        $refund = $refunds->request($invoice, 400, 'Test refund');
        self::assertSame(HuifuRefund::STATUS_SUCCEEDED, $refund->getStatus());
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(400, $refund->getProviderAmount());
        self::assertSame(400, $refund->getConfirmedAmount());
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $invoice->getStatus());

        $pendingClient = new FakeHuifuClient();
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
        );
        $pending = $pendingRefunds->request($invoice, 600, 'Pending refund');
        self::assertSame(HuifuRefund::STATUS_PROCESSING, $pending->getStatus());
        self::assertFalse($pending->isCoreApplied());
        $em->refresh($invoice);
        self::assertSame(400, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $invoice->getStatus());
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
        );
        $result = $service->start($invoice, 'native');
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        $attempt = $em->getRepository(HuifuPaymentAttempt::class)->findOneBy(['invoice' => $invoice]);
        self::assertInstanceOf(HuifuPaymentAttempt::class, $attempt);
        self::assertSame($store->getUuid(), $attempt->getStoreUuid());
        self::assertSame(HuifuPaymentAttempt::STATUS_CLOSED, $attempt->getStatus());
        self::assertSame(1200, $attempt->getAmount());
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
