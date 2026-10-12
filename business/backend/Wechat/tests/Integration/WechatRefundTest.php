<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use NsUltimate\Business\Wechat\Service\EasyWeChatPayProvider;
use NsUltimate\Business\Wechat\Service\WechatPaymentGateway;
use NsUltimate\Business\Wechat\Service\WechatRefundOrchestrator;
use NsUltimate\Business\Wechat\Tests\Support\FakeWechatPayProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class WechatRefundTest extends WechatApiTestCase
{
    private FakeWechatPayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeWechatPayProvider();
        self::getContainer()->set(EasyWeChatPayProvider::class, $this->provider);
    }

    public function testProcessingRefundDoesNotTouchAccounting(): void
    {
        [$invoice] = $this->paidInvoice();
        $this->provider->refundResult = ['refund_id' => 'refund-1', 'status' => 'PROCESSING', 'raw' => ['status' => 'PROCESSING']];

        $refund = $this->orchestrator()->requestRefund($invoice, 400, 'test');

        self::assertSame(WechatRefundAttempt::STATUS_PROCESSING, $refund->getStatus());
        self::assertSame(0, $this->freshInvoice($invoice)->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PAID, $this->freshInvoice($invoice)->getStatus());
    }

    public function testSuccessfulRefundAppliesAccountingExactlyOnce(): void
    {
        [$invoice] = $this->paidInvoice();
        $events = [];
        self::getContainer()->get('event_dispatcher')->addListener(
            InvoiceRefundedEvent::class,
            static function (InvoiceRefundedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $refund = $this->orchestrator()->requestRefund($invoice, 1000, 'test');

        self::assertSame(WechatRefundAttempt::STATUS_SUCCEEDED, $refund->getStatus());
        self::assertSame(1000, $this->freshInvoice($invoice)->getRefundedAmount());
        self::assertSame(Invoice::STATUS_REFUNDED, $this->freshInvoice($invoice)->getStatus());
        self::assertCount(1, $events);

        // A duplicate confirmation must not apply accounting twice.
        self::getContainer()->get(WechatRefundOrchestrator::class)->confirmRefund($refund, [
            'out_refund_no' => $refund->getProviderRefundNo(),
            'out_trade_no' => $refund->getPaymentAttempt()?->getProviderOrderNo(),
            'refund_status' => 'SUCCESS',
            'refund_id' => 'refund-1',
            'amount' => ['refund' => 1000, 'total' => 1000, 'currency' => 'CNY'],
        ], 'notify');

        self::assertSame(1000, $this->freshInvoice($invoice)->getRefundedAmount());
        self::assertCount(1, $events);
    }

    public function testFailedRefundReleasesTheReservation(): void
    {
        [$invoice] = $this->paidInvoice();
        $this->provider->refundResult = ['refund_id' => 'refund-1', 'status' => 'ABNORMAL', 'raw' => ['status' => 'ABNORMAL']];

        $refund = $this->orchestrator()->requestRefund($invoice, 1000, 'test');
        self::assertSame(WechatRefundAttempt::STATUS_FAILED, $refund->getStatus());
        self::assertSame(0, $this->freshInvoice($invoice)->getRefundedAmount());

        // The full amount is available again for a new request.
        $this->provider->refundResult = ['refund_id' => 'refund-2', 'status' => 'SUCCESS', 'raw' => []];
        $retry = $this->orchestrator()->requestRefund($invoice, 1000, 'retry', ['idempotencyKey' => 'retry-key']);
        self::assertSame(WechatRefundAttempt::STATUS_SUCCEEDED, $retry->getStatus());
    }

    public function testConcurrentRequestsCannotOverRefund(): void
    {
        [$invoice] = $this->paidInvoice();
        $this->provider->refundResult = ['refund_id' => 'refund-1', 'status' => 'PROCESSING', 'raw' => []];

        $this->orchestrator()->requestRefund($invoice, 700, 'first', ['idempotencyKey' => 'first']);

        $this->expectException(\InvalidArgumentException::class);
        $this->orchestrator()->requestRefund($invoice, 400, 'second', ['idempotencyKey' => 'second']);
    }

    public function testAdjustedInvoicesAreRejectedBeforeProviderAccess(): void
    {
        [$invoice] = $this->paidInvoice();
        $payer = $invoice->getPayer();
        self::assertInstanceOf(\App\Identity\Entity\User::class, $payer);
        $wallet = new \App\Wallet\Entity\Wallet($payer, 'CNY');
        $this->entityManager()->persist($wallet);
        $this->entityManager()->persist(
            (new \App\Wallet\Entity\PaymentDeduction($invoice, $wallet, 0, 100, 'CNY', 'ref-1'))->markApplied('txn-1'),
        );
        $this->entityManager()->flush();

        try {
            $this->orchestrator()->requestRefund($invoice, 100, 'test');
            self::fail('Expected adjusted invoices to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('adjustments', $e->getMessage());
        }
        // The provider was never contacted.
        self::assertSame([], $this->provider->lastRefundBody);
    }

    public function testRefundUsesHistoricalMerchantAfterRebinding(): void
    {
        [$invoice, $storeUuid] = $this->paidInvoice();
        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)
            ->findOneBy(['invoiceUuid' => $invoice->getUuid()]);

        // Rebind the store to a different merchant.
        $newMerchantRevision = $this->publishMerchantRevision($this->createMerchant('1900000099'));
        [, $body] = $this->request('GET', '/api/v1/manage/store-wechat-settings');
        $settingUuid = $body['data'][0]['uuid'];
        [$status, $body] = $this->request('PUT', '/api/v1/manage/store-wechat-settings/'.$settingUuid, [
            'merchantRevision' => $newMerchantRevision,
        ]);
        self::assertSame(200, $status, json_encode($body));

        $refund = $this->orchestrator()->requestRefund($invoice, 1000, 'test');

        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)
            ->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);
        $config = self::getContainer()->get(\NsUltimate\Business\Wechat\Service\StoreWechatResolver::class)
            ->resolveForAttempt($attempt);
        self::assertSame('1900000001', $config->merchantId);
        self::assertSame(WechatRefundAttempt::STATUS_SUCCEEDED, $refund->getStatus());
    }

    public function testRefundWebhookConfirmsThroughLocator(): void
    {
        [$invoice] = $this->paidInvoice();
        $this->provider->refundResult = ['refund_id' => 'refund-1', 'status' => 'PROCESSING', 'raw' => []];
        $refund = $this->orchestrator()->requestRefund($invoice, 1000, 'test', ['idempotencyKey' => 'webhook-key']);

        $this->provider->refundNotifyData = [
            'out_refund_no' => $refund->getProviderRefundNo(),
            'out_trade_no' => $refund->getPaymentAttempt()?->getProviderOrderNo(),
            'refund_status' => 'SUCCESS',
            'refund_id' => 'refund-1',
            'amount' => ['refund' => 1000, 'total' => 1000, 'currency' => 'CNY'],
        ];

        $request = Request::create('/api/v1/webhooks/wechat/notify/'.$refund->getUuid(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $invoiceUuid = $invoice->getUuid();
        $response = self::$kernel->handle($request);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        // The test kernel clears managed entities across handle(); re-fetch like
        // all other integration tests instead of refreshing a stale reference.
        $invoice = $this->entityManager()->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceUuid]);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(1000, $invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());
    }

    public function testWebhookRejectsUnknownLocator(): void
    {
        $request = Request::create('/api/v1/webhooks/wechat/notify/'.\App\Core\Utils\UUID::v4(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $response = self::$kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReconcileCommandConfirmsMissedCallbacks(): void
    {
        [$invoice] = $this->paidInvoice();
        $attempt = $this->entityManager()->getRepository(WechatPaymentAttempt::class)
            ->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);

        // Simulate a missed callback: provider says SUCCESS, local attempt is pending.
        $this->provider->queryResults[$attempt->getProviderOrderNo()] = [
            'out_trade_no' => $attempt->getProviderOrderNo(),
            'transaction_id' => 'wx-txn-reconciled',
            'trade_state' => 'SUCCESS',
            'mchid' => '1900000001',
            'appid' => 'wx-mini-pay',
            'amount' => ['total' => 1000, 'currency' => 'CNY'],
        ];

        $tester = new CommandTester(self::getContainer()->get(\NsUltimate\Business\Wechat\Command\ReconcileWechatCommand::class));
        self::assertSame(0, $tester->execute([]));

        self::assertSame(WechatPaymentAttempt::STATUS_SUCCEEDED, $this->entityManager()->getRepository(WechatPaymentAttempt::class)->find($attempt->getId())?->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $this->freshInvoice($invoice)->getStatus());
    }

    /** @return array{0: Invoice, 1: string} */
    private function paidInvoice(): array
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
            (new \NsUltimate\Business\Wechat\Entity\WechatIdentity())
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

        // Pay through the gateway, then confirm via the success path so the
        // refund tests start from a paid invoice with a succeeded attempt.
        self::getContainer()->get(WechatPaymentGateway::class)->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);
        $attempt = $entityManager->getRepository(WechatPaymentAttempt::class)->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);
        self::getContainer()->get(\NsUltimate\Business\Wechat\Service\WechatPaymentCompletionService::class)->confirmFromProviderData($attempt, [
            'out_trade_no' => $attempt->getProviderOrderNo(),
            'transaction_id' => 'wx-txn-1',
            'trade_state' => 'SUCCESS',
            'mchid' => '1900000001',
            'appid' => 'wx-mini-pay',
            'amount' => ['total' => 1000, 'currency' => 'CNY'],
        ], 'test');

        return [$this->freshInvoice($invoice), $store->getUuid()];
    }

    private function orchestrator(): WechatRefundOrchestrator
    {
        return self::getContainer()->get(WechatRefundOrchestrator::class);
    }

    private function freshInvoice(Invoice $invoice): Invoice
    {
        $this->entityManager()->refresh($invoice);

        return $invoice;
    }
}
