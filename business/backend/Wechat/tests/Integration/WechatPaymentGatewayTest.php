<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayRegistry;
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
        self::assertStringContainsString('locator='.$attempt->getUuid(), $this->provider->lastBody['notify_url'] ?? '');
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
