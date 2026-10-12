<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Payment\Service\InvoiceService;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatIdentity;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Service\EasyWeChatPayProvider;
use NsUltimate\Business\Wechat\Service\WechatPaymentGateway;
use NsUltimate\Business\Wechat\Tests\Support\FakeWechatPayProvider;
use Symfony\Component\HttpFoundation\Request;

final class WechatPaymentGatewayTest extends WechatApiTestCase
{
    private FakeWechatPayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeWechatPayProvider();
        self::getContainer()->set(EasyWeChatPayProvider::class, $this->provider);
    }

    public function testStoreGatewayReplacesTheCoreRegistration(): void
    {
        $gateway = self::getContainer()->get(PaymentGatewayRegistry::class)->get('wechat');
        self::assertInstanceOf(WechatPaymentGateway::class, $gateway);
    }

    public function testJsapiPaymentCreatesAPinnedAttemptAndReturnsSignedPayload(): void
    {
        [$invoice, $payer] = $this->invoiceForOrder();
        $store = $invoice->getPayer() === null ? null : null;

        $gateway = self::getContainer()->get(WechatPaymentGateway::class);
        $result = $gateway->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_JSAPI]);

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('prepay-1', $result->payload['prepay_id'] ?? null);
        self::assertNotNull($payer);

        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);
        self::assertSame(WechatPaymentAttempt::STATUS_PENDING, $attempt->getStatus());
        self::assertSame($invoice->getOutTradeNo(), $attempt->getProviderOrderNo());
        self::assertSame(1000, $attempt->getExpectedAmount());
        self::assertSame('openid-pay', $attempt->getPayerIdentity()?->getOpenid());
        self::assertStringContainsString('/'.$attempt->getUuid(), $this->provider->lastBody['notify_url'] ?? '');
    }

    public function testInvoiceCommitsBeforeWechatAttemptAndProviderCall(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $entityManager = $this->entityManager();
        $this->provider->beforePrepay = function () use ($entityManager, $invoice): void {
            self::assertFalse($entityManager->getConnection()->isTransactionActive());
            self::assertSame(1, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM wechat_payment_attempt WHERE invoice_uuid = ?',
                [$invoice->getUuid()],
            ));
        };

        $result = self::getContainer()->get(InvoiceService::class)->pay(
            $invoice,
            Invoice::PAYMENT_WECHAT,
            ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE],
        );

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertFalse($entityManager->getConnection()->isTransactionActive());
    }

    public function testProviderTimeoutLeavesDurableUnknownAttemptForReconciliation(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $this->provider->prepayException = new \RuntimeException('synthetic provider timeout');

        $result = self::getContainer()->get(InvoiceService::class)->pay(
            $invoice,
            Invoice::PAYMENT_WECHAT,
            ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE],
        );

        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)
            ->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);
        self::assertSame(WechatPaymentAttempt::STATUS_UNKNOWN, $attempt->getStatus());
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        $this->entityManager()->refresh($invoice);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
    }

    public function testNativePaymentReturnsProviderQrUrl(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $gateway = self::getContainer()->get(WechatPaymentGateway::class);

        $result = $gateway->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);
        self::assertSame('weixin://wxpay/bizpayurl?pr=abc', $result->payUrl);
    }

    public function testNotifyVerifiesAndConfirmsTheAttempt(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $gateway = self::getContainer()->get(WechatPaymentGateway::class);
        $gateway->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);

        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);

        $this->provider->notifyData = [
            'out_trade_no' => $attempt->getProviderOrderNo(),
            'transaction_id' => 'wx-txn-1',
            'trade_state' => 'SUCCESS',
            'mchid' => '1900000001',
            'appid' => 'wx-mini-pay',
            'success_time' => '2026-10-12T10:00:00+08:00',
            'amount' => ['total' => 1000, 'currency' => 'CNY'],
        ];

        $result = $gateway->notify($this->notifyRequest($attempt->getUuid()));
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(1000, $result->amount);
        self::assertSame('wx-txn-1', $result->transactionId);
    }

    public function testNotifyRejectsAmountMismatch(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $gateway = self::getContainer()->get(WechatPaymentGateway::class);
        $gateway->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);
        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)->findOneBy(['invoiceUuid' => $invoice->getUuid()]);

        $this->provider->notifyData = [
            'out_trade_no' => $attempt?->getProviderOrderNo(),
            'trade_state' => 'SUCCESS',
            'mchid' => '1900000001',
            'amount' => ['total' => 999, 'currency' => 'CNY'],
        ];

        $this->expectException(PaymentVerificationException::class);
        $gateway->notify($this->notifyRequest((string) $attempt?->getUuid()));
    }

    public function testNotifyRejectsUnknownLocator(): void
    {
        $gateway = self::getContainer()->get(WechatPaymentGateway::class);

        $this->expectException(PaymentVerificationException::class);
        $gateway->notify($this->notifyRequest(\App\Core\Utils\UUID::v4()));
    }

    public function testCoreRefundPathIsDisabled(): void
    {
        [$invoice] = $this->invoiceForOrder();
        $gateway = self::getContainer()->get(WechatPaymentGateway::class);

        $this->expectException(\RuntimeException::class);
        $gateway->refund($invoice, 100, 100, 'test');
    }

    public function testOfficialAccountJsapiUsesOfficialIdentityAndAppId(): void
    {
        $store = $this->createStore('OA');
        $officialRevision = $this->publishOfficialApplication('wx-official-pay');
        [$status, $body] = $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $store->getUuid(),
            'officialRevision' => $officialRevision,
            'merchantRevision' => $this->publishMerchantRevision($this->createMerchant('1900000021')),
            'capabilities' => [StoreWechatSetting::CAP_PAYMENT_OFFICIAL => true],
        ]);
        self::assertSame(201, $status, json_encode($body));

        $payer = $this->createPayer('wx-official-pay', 'official', 'official-openid');
        $invoice = $this->createInvoiceForStore($store, $payer);

        $gateway = self::getContainer()->get(WechatPaymentGateway::class);
        $result = $gateway->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_OFFICIAL_JSAPI]);

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('wx-official-pay', $result->payload['appId'] ?? null);
        self::assertSame('official_jsapi', $result->payload['tradeType'] ?? null);
        self::assertSame('appid=wx-official-pay', $this->provider->lastBody['appid'] === 'wx-official-pay' ? 'appid=wx-official-pay' : '');
        self::assertSame(['openid' => 'official-openid'], $this->provider->lastBody['payer'] ?? null);
    }

    public function testPartnerModeBuildsServiceProviderFields(): void
    {
        $store = $this->createStore('SP');
        [, $merchant] = $this->request('POST', '/api/v1/manage/wechat-merchants', [
            'name' => 'Service provider',
            'merchantId' => '1900000031',
            'mode' => 'partner',
        ]);
        [, $merchantRevision] = $this->request('POST', '/api/v1/manage/wechat-merchant-revisions', [
            'merchant' => $merchant['data']['id'],
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ]);
        $this->request('PUT', '/api/v1/manage/wechat-merchant-revisions/'.$merchantRevision['data']['uuid'], ['state' => 'published']);

        $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $store->getUuid(),
            'miniappRevision' => $this->publishApplicationRevision($this->createApplication('wx-mini-sp'), 'secret-sp'),
            'merchantRevision' => $merchantRevision['data']['uuid'],
            'subMerchantId' => '1900000032',
            'subAppId' => 'wx-sub-app',
            'capabilities' => [StoreWechatSetting::CAP_PAYMENT_NATIVE => true],
        ]);

        $invoice = $this->createInvoiceForStore($store, null);
        self::getContainer()->get(WechatPaymentGateway::class)->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);

        self::assertSame('wx-mini-sp', $this->provider->lastBody['sp_appid'] ?? null);
        self::assertSame('1900000031', $this->provider->lastBody['sp_mchid'] ?? null);
        self::assertSame('1900000032', $this->provider->lastBody['sub_mchid'] ?? null);
        self::assertSame('wx-sub-app', $this->provider->lastBody['sub_appid'] ?? null);
        self::assertArrayNotHasKey('appid', $this->provider->lastBody);
        self::assertArrayNotHasKey('mchid', $this->provider->lastBody);
    }

    private function publishOfficialApplication(string $appId): string
    {
        [, $application] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'Official '.$appId,
            'type' => 'official',
            'appId' => $appId,
        ]);
        [, $revision] = $this->request('POST', '/api/v1/manage/wechat-application-revisions', [
            'application' => $application['data']['id'],
            'secret' => 'official-secret',
            'officialToken' => 'official-token',
            'officialAesKey' => str_repeat('a', 43),
            'redirectHosts' => ['shop.example.com'],
        ]);
        $this->request('PUT', '/api/v1/manage/wechat-application-revisions/'.$revision['data']['uuid'], ['state' => 'published']);

        return $revision['data']['uuid'];
    }

    private function createPayer(string $appId, string $applicationType, string $openid): \App\Identity\Entity\User
    {
        $entityManager = $this->entityManager();
        $payer = (new \App\Identity\Entity\User())
            ->setEmail('payer-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('payer-'.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_USER']);
        $entityManager->persist($payer);
        $entityManager->persist(
            (new WechatIdentity())
                ->setUser($payer)
                ->setAppId($appId)
                ->setApplicationType($applicationType)
                ->setOpenid($openid),
        );
        $entityManager->flush();

        return $payer;
    }

    private function createInvoiceForStore(\App\Store\Entity\Store $store, ?\App\Identity\Entity\User $payer): Invoice
    {
        $entityManager = $this->entityManager();
        $order = new Order();
        $entityManager->persist($order);
        $entityManager->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));

        $invoice = new Invoice();
        $invoice->setSourceType('trade_order');
        $invoice->setSourceId($order->getUuid());
        $invoice->setAmount(1000);
        $invoice->setPayer($payer);
        $entityManager->persist($invoice);
        $entityManager->flush();

        return $invoice;
    }

    /** @return array{0: Invoice, 1: \App\Identity\Entity\User} */
    private function invoiceForOrder(): array
    {
        $store = $this->createStore('PAY');
        $this->createStoreSetting(
            $store->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-pay'), 'secret-pay'),
            $this->publishMerchantRevision($this->createMerchant('1900000001')),
            [
                StoreWechatSetting::CAP_PAYMENT_JSAPI => true,
                StoreWechatSetting::CAP_PAYMENT_NATIVE => true,
            ],
        );

        $entityManager = $this->entityManager();
        $payer = (new \App\Identity\Entity\User())
            ->setEmail('payer-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('payer-'.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_USER']);
        $entityManager->persist($payer);
        $entityManager->persist(
            (new WechatIdentity())
                ->setUser($payer)
                ->setAppId('wx-mini-pay')
                ->setApplicationType('miniapp')
                ->setOpenid('openid-pay'),
        );

        $order = new Order();
        $entityManager->persist($order);
        $entityManager->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));

        $invoice = new Invoice();
        $invoice->setSourceType('trade_order');
        $invoice->setSourceId($order->getUuid());
        $invoice->setAmount(1000);
        $invoice->setPayer($payer);
        $entityManager->persist($invoice);
        $entityManager->flush();

        return [$invoice, $payer];
    }

    private function notifyRequest(string $locator): Request
    {
        return Request::create(
            '/api/payment/notify/wechat?locator='.$locator,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );
    }
}
