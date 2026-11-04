<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Common\Entity\Setting;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Wechat\Entity\WechatUser;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchantStoreGrant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuWebhookEvent;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Integration\Backend\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../Support/FakeHuifuClient.php';

final class CustomerHuifuPaymentApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakeHuifuClient $huifu;
    private Store $store;
    private User $owner;
    private User $payer;
    private Specification $specification;
    private string $ownerToken;
    private string $payerToken;
    private string $privateKey = '';
    private bool $networkFailure = false;
    private int $durableSubmissions = 0;

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
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->huifu = new FakeHuifuClient($this->privateKey);

        // Install before any HTTP request can instantiate the composed gateway.
        $client = $this->createStub(HuifuClientInterface::class);
        $client->method('post')->willReturnCallback(function (string $path, array $payload, string $environment): array {
            // A second connection observes only committed rows, not Core's pay transaction.
            $connection = DriverManager::getConnection($this->em->getConnection()->getParams());
            try {
                self::assertSame(1, (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM huifu_payment_attempt WHERE req_seq_id = ?',
                    [$payload['data']['req_seq_id']],
                ));
                ++$this->durableSubmissions;
            } finally {
                $connection->close();
            }
            if ($this->networkFailure) {
                $this->huifu->requests[] = ['path' => $path, 'payload' => $payload, 'environment' => $environment];
                throw new \RuntimeException('Private provider transport diagnostics must not be exposed');
            }

            return $this->huifu->post($path, $payload, $environment);
        });
        self::getContainer()->set(HuifuClientInterface::class, $client);

        $this->store = new Store('customer-huifu', 'Customer payment store');
        $this->store->setSettings(['order' => ['requireAcceptance' => false]]);
        $this->owner = (new User())->setUsername('private-order-owner')->setEmail('private-owner@example.test');
        $this->payer = (new User())->setUsername('private-actual-payer')->setEmail('private-payer@example.test');
        $product = (new Product($this->store))->setName('Lunch');
        $this->specification = (new Specification())->setProduct($product)->setName('Regular')->setPrice(1200);
        $provider = new HuifuProviderProfile('Private provider profile', 'production', '6666000000000001', 'YMFZS');
        $provider->setCredentials([
            'merchant_private_key' => $this->privateKey,
            'huifu_public_key' => $details['key'],
            'async_public_key' => $details['key'],
        ], self::getContainer()->get(HuifuSecretCipher::class));
        $merchant = new HuifuMerchant('Private merchant profile', '6666000000000101', $provider, ['native', 'jspay']);
        $setting = new StoreHuifuSetting($this->store->getUuid(), $merchant);
        $setting->setAllowedMethods(['native', 'jspay']);
        $setting->setDefaultMethod('native');
        foreach ([$this->store, $this->owner, $this->payer, $product, $this->specification,
            $provider, $merchant, $setting, new HuifuMerchantStoreGrant($merchant, $this->store->getUuid())] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $tokens = self::getContainer()->get(TokenManager::class);
        $this->ownerToken = $tokens->createAccessToken($this->owner);
        $this->payerToken = $tokens->createAccessToken($this->payer);
    }

    public function testDifferentAuthenticatedPayerLaunchesAndSignedSuccessPaysTheOriginalOwnersOrder(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $publicBefore = $this->json($this->request('GET', '/api/v1/public/orders/'.$orderUuid.'/payment'), 200);
        self::assertNull($publicBefore['data']['invoice']);
        self::assertCount(0, $this->huifu->requests);

        // Core overwrites this JSON value with the authenticated User before adapters run.
        $launch = $this->launch($orderUuid, ['_payer' => $this->owner->getUuid()]);
        $invoice = $this->invoice($launch['invoice']['uuid']);
        $attempt = $this->attempt($launch['payload']['attemptUuid']);
        self::assertSame($this->payer->getUuid(), $invoice->getPayer()?->getUuid());
        self::assertSame($this->owner->getUuid(), $this->order($orderUuid)->getUser()?->getUuid());
        self::assertSame($invoice->getUuid(), $this->order($orderUuid)->getInvoiceId());
        self::assertSame('trade_order', $invoice->getSourceType());
        self::assertSame($orderUuid, $invoice->getSourceId());
        self::assertSame(Invoice::SCENE_ORDER, $invoice->getScene());
        self::assertSame(2400, $invoice->getAmount());
        self::assertSame('CNY', $invoice->getCurrency());
        self::assertSame('native', $attempt->getChannel());
        self::assertSame('T_NATIVE', $attempt->getTradeType());
        self::assertSame(HuifuPaymentAttempt::STATUS_PENDING, $attempt->getStatus());
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());
        self::assertCount(1, $this->huifu->requests);
        self::assertSame(1, $this->durableSubmissions);
        self::assertSame('24.00', $this->huifu->requests[0]['payload']['data']['trans_amt']);
        $notifyUrl = $this->huifu->requests[0]['payload']['data']['notify_url'];
        self::assertStringEndsWith('/api/payment/notify/huifu/'.$attempt->getUuid(), $notifyUrl);
        $publicOrigin = $_ENV['DEFAULT_URI'] ?? $_SERVER['DEFAULT_URI'] ?? null;
        self::assertIsString($publicOrigin);
        self::assertStringStartsWith(rtrim($publicOrigin, '/').'/', $notifyUrl);

        // Payment access must not grant private order access or cancellation rights.
        self::assertSame(404, $this->request('GET', '/api/v1/app/orders/'.$orderUuid, $this->payerToken)->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/v1/app/orders/'.$orderUuid.'/cancel', $this->payerToken)->getStatusCode());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());

        $success = $this->notify($attempt, 'S');
        self::assertSame(200, $success->getStatusCode(), (string) $success->getContent());
        self::assertSame('RECV_ORD_ID_'.$attempt->getReqSeqId(), $success->getContent());
        self::assertSame(Invoice::STATUS_PAID, $this->invoice($invoice->getUuid())->getStatus());
        self::assertSame(Order::STATUS_PAID, $this->order($orderUuid)->getStatus());
        self::assertSame($this->owner->getUuid(), $this->order($orderUuid)->getUser()?->getUuid());
        self::assertSame($this->payer->getUuid(), $this->invoice($invoice->getUuid())->getPayer()?->getUuid());

        self::assertSame(200, $this->notify($attempt, 'S')->getStatusCode());
        self::assertSame(1, $this->em->getRepository(HuifuWebhookEvent::class)->count([]));
        self::assertSame(200, $this->notify($attempt, 'F')->getStatusCode());
        self::assertSame(HuifuPaymentAttempt::STATUS_SUCCEEDED, $this->attempt($attempt->getUuid())->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $this->invoice($invoice->getUuid())->getStatus());
        self::assertSame(Order::STATUS_PAID, $this->order($orderUuid)->getStatus());
        self::assertCount(1, $this->huifu->requests);

        $public = $this->request('GET', '/api/v1/public/orders/'.$orderUuid.'/payment');
        $data = $this->json($public, 200)['data'];
        self::assertSame('paid', $data['status']);
        self::assertSame($invoice->getUuid(), $data['invoice']['uuid']);
        self::assertArrayNotHasKey('payload', $data);
        $this->assertNoPrivateFields($public);
    }

    public function testSignedFailureLeavesOrderConfirmedAndAllowsFreshLaunch(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $first = $this->launch($orderUuid, ['gateway' => 'native', 'tradeType' => 'T_NATIVE']);
        $attempt = $this->attempt($first['payload']['attemptUuid']);
        $response = $this->notify($attempt, 'F');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(HuifuPaymentAttempt::STATUS_FAILED, $this->attempt($attempt->getUuid())->getStatus());
        self::assertSame(Invoice::STATUS_FAILED, $this->invoice($first['invoice']['uuid'])->getStatus());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());

        $retry = $this->launch($orderUuid);
        self::assertNotSame($first['payload']['attemptUuid'], $retry['payload']['attemptUuid']);
        self::assertCount(2, $this->huifu->requests);
        self::assertSame(2, $this->durableSubmissions);
        self::assertSame(200, $this->notify($this->attempt($retry['payload']['attemptUuid']), 'S')->getStatusCode());
        self::assertSame(Order::STATUS_PAID, $this->order($orderUuid)->getStatus());
    }

    public function testDifferentPayersMayHaveSimultaneousPayingInvoices(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $ownerLaunch = $this->launch($orderUuid, token: $this->ownerToken);
        $payerLaunch = $this->launch($orderUuid);
        self::assertNotSame($ownerLaunch['invoice']['uuid'], $payerLaunch['invoice']['uuid']);
        self::assertNotSame($ownerLaunch['payload']['attemptUuid'], $payerLaunch['payload']['attemptUuid']);
        self::assertSame(Invoice::STATUS_PAYING, $this->invoice($ownerLaunch['invoice']['uuid'])->getStatus());
        self::assertSame(Invoice::STATUS_PAYING, $this->invoice($payerLaunch['invoice']['uuid'])->getStatus());
        self::assertSame($this->owner->getUuid(), $this->invoice($ownerLaunch['invoice']['uuid'])->getPayer()?->getUuid());
        self::assertSame($this->payer->getUuid(), $this->invoice($payerLaunch['invoice']['uuid'])->getPayer()?->getUuid());
        self::assertSame($this->owner->getUuid(), $this->order($orderUuid)->getUser()?->getUuid());
        self::assertSame($payerLaunch['invoice']['uuid'], $this->order($orderUuid)->getInvoiceId());
        self::assertCount(2, $this->huifu->requests);
    }

    public function testJsapiUsesTheActualPayersStoreScopedOpenid(): void
    {
        $application = new WechatApplication('Store official account', WechatApplication::TYPE_OFFICIAL, 'wx-customer-huifu');
        foreach ([$application, new StoreWechatSetting($this->store->getUuid(), null, $application),
            new WechatUser($this->owner, 'private-owner-openid', WechatUser::APP_TYPE_OFFICIAL, $application->getAppId()),
            new WechatUser($this->payer, 'private-payer-openid', WechatUser::APP_TYPE_OFFICIAL, $application->getAppId())] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $launch = $this->launch($orderUuid, ['gateway' => 'jspay', 'tradeType' => 'T_JSAPI']);
        self::assertSame('jspay', $launch['payload']['channel']);
        self::assertSame('T_JSAPI', $launch['payload']['tradeType']);
        self::assertSame(['openid' => 'private-payer-openid'], json_decode(
            $this->huifu->requests[0]['payload']['data']['wx_data'], true, flags: JSON_THROW_ON_ERROR,
        ));
        self::assertSame($this->payer->getUuid(), $this->invoice($launch['invoice']['uuid'])->getPayer()?->getUuid());
        self::assertCount(1, $this->huifu->requests);
        self::assertSame(200, $this->notify($this->attempt($launch['payload']['attemptUuid']), 'S')->getStatusCode());
        self::assertSame(Order::STATUS_PAID, $this->order($orderUuid)->getStatus());
    }

    #[DataProvider('invalidNotifications')]
    public function testInvalidNotificationsCannotPayTheInvoiceOrOrder(array $overrides, bool $invalidSignature): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $launch = $this->launch($orderUuid);
        $attempt = $this->attempt($launch['payload']['attemptUuid']);
        $response = $this->notify($attempt, 'S', $overrides, $invalidSignature);
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(Invoice::STATUS_PAYING, $this->invoice($launch['invoice']['uuid'])->getStatus());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());
        self::assertSame(HuifuPaymentAttempt::STATUS_PENDING, $this->attempt($attempt->getUuid())->getStatus());
        self::assertCount(1, $this->huifu->requests);
    }

    public static function invalidNotifications(): iterable
    {
        yield 'invalid signature' => [[], true];
        yield 'wrong amount' => [['trans_amt' => '23.99'], false];
        yield 'wrong currency' => [['currency' => 'USD'], false];
        yield 'wrong merchant' => [['huifu_id' => '6666000000000999'], false];
        yield 'wrong request sequence' => [['req_seq_id' => 'FOREIGN-ATTEMPT'], false];
    }

    #[DataProvider('thirdPartyPolicies')]
    public function testThirdPartyPaymentRequiresAnExplicitEnabledGlobalPolicy(?string $value): void
    {
        if ($value !== null) {
            $this->allowThirdPartyPayment($value);
        }
        $orderUuid = $this->createConfirmedOrder();
        $response = $this->request('POST', $this->paymentPath($orderUuid), $this->payerToken, ['payment' => 'huifu', 'gateway' => 'native', 'tradeType' => 'T_NATIVE']);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertCount(0, $this->huifu->requests);
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());
        // Owners can still pay without permission to pay somebody else's order.
        $this->launch($orderUuid, token: $this->ownerToken);
    }

    public static function thirdPartyPolicies(): iterable
    {
        yield 'missing' => [null];
        yield 'disabled' => ['false'];
    }

    public function testAnonymousCannotLaunchPayment(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        self::assertSame(401, $this->request('POST', $this->paymentPath($orderUuid), body: ['payment' => 'huifu'])->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertCount(0, $this->huifu->requests);
    }

    public function testGenericInvoicePaymentCannotBypassTheCustomerHuifuWorkflow(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $launch = $this->launch($orderUuid);
        $invoice = $this->invoice($launch['invoice']['uuid']);
        $response = $this->request('POST', '/api/v1/app/invoices/'.$invoice->getId().'/pay/huifu', $this->payerToken, [
            'huifuAttemptUuid' => $launch['payload']['attemptUuid'], 'huifuAttemptProof' => 'spoofed-proof',
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());
        self::assertSame(1, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertCount(1, $this->huifu->requests);
    }

    #[DataProvider('invalidTradeTypes')]
    public function testChannelAndTradeTypeMustMatch(string $channel, string $tradeType): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $response = $this->request('POST', $this->paymentPath($orderUuid), $this->payerToken, [
            'payment' => 'huifu', 'gateway' => $channel, 'tradeType' => $tradeType,
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertCount(0, $this->huifu->requests);
    }

    public static function invalidTradeTypes(): iterable
    {
        yield 'native cannot use JSAPI' => ['native', 'T_JSAPI'];
        yield 'JSAPI cannot use native' => ['jspay', 'T_NATIVE'];
    }

    #[DataProvider('spoofedFields')]
    public function testRequestCannotOverrideServerControlledPaymentContext(string $field, mixed $value): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $response = $this->request('POST', $this->paymentPath($orderUuid), $this->payerToken, [
            'payment' => 'huifu', 'gateway' => 'native', 'tradeType' => 'T_NATIVE', $field => $value,
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertNull($this->order($orderUuid)->getInvoiceId());
        self::assertCount(0, $this->huifu->requests);
    }

    public static function spoofedFields(): iterable
    {
        foreach (['payer', 'payerUuid', 'user', 'openid', 'storeUuid', 'merchantUuid', 'huifuId',
            'providerProfileUuid', 'invoiceUuid', 'huifuAttemptUuid', 'huifuAttemptProof',
            'merchantPrivateKey', 'auth', 'proof', 'clientIp'] as $field) {
            yield $field => [$field, 'spoofed-private-value'];
        }
        yield 'amount' => ['amount', 1];
        yield 'currency' => ['currency', 'USD'];
        yield 'options' => ['options', ['huifuAttemptProof' => 'spoofed-proof']];
        yield 'preparation context' => ['_huifuPreparation', ['orderUuid' => 'spoofed-order']];
        yield 'customer context' => ['_customerOrder', ['payerUuid' => 'spoofed-payer']];
    }

    public function testNetworkTimeoutReturnsSafeUnknownWithDurableAttemptAndNoSecondSubmission(): void
    {
        $this->allowThirdPartyPayment();
        $orderUuid = $this->createConfirmedOrder();
        $this->networkFailure = true;
        $response = $this->request('POST', $this->paymentPath($orderUuid), $this->payerToken, [
            'payment' => 'huifu', 'gateway' => 'native', 'tradeType' => 'T_NATIVE',
        ]);
        $body = $this->json($response, 200);
        self::assertSame('unknown', $body['data']['status']);
        $this->assertNoPrivateFields($response);
        self::assertStringNotContainsString('transport diagnostics', (string) $response->getContent());
        $attemptUuid = $body['data']['payload']['attemptUuid'];
        $this->em->clear();
        $attempt = $this->attempt($attemptUuid);
        self::assertSame(HuifuPaymentAttempt::STATUS_UNKNOWN, $attempt->getStatus());
        self::assertSame($body['data']['invoice']['uuid'], $attempt->getInvoice()?->getUuid());
        self::assertSame($this->payer->getUuid(), $attempt->getInvoice()?->getPayer()?->getUuid());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($orderUuid)->getStatus());
        self::assertSame(1, $this->durableSubmissions);
        self::assertCount(1, $this->huifu->requests);

        $retry = $this->request('POST', $this->paymentPath($orderUuid), $this->payerToken, [
            'payment' => 'huifu', 'gateway' => 'native', 'tradeType' => 'T_NATIVE',
        ]);
        self::assertContains($retry->getStatusCode(), [200, 400], (string) $retry->getContent());
        if ($retry->getStatusCode() === 200) {
            self::assertSame('unknown', $this->json($retry, 200)['data']['status']);
        }
        self::assertSame(1, $this->em->getRepository(HuifuPaymentAttempt::class)->count([]));
        self::assertCount(1, $this->huifu->requests);
        // An unknown synchronous result can still be resolved by a valid callback.
        self::assertSame(200, $this->notify($attempt, 'S')->getStatusCode());
        self::assertSame(Order::STATUS_PAID, $this->order($orderUuid)->getStatus());
    }

    private function allowThirdPartyPayment(string $value = 'true'): void
    {
        $this->em->persist((new Setting('payment.allowThirdPartyPayment'))->setType('boolean')->setValue($value));
        $this->em->flush();
    }

    private function createConfirmedOrder(): string
    {
        $response = $this->request('POST', '/api/v1/app/orders', $this->ownerToken, [
            'items' => [['specificationId' => $this->specification->getId(), 'quantity' => 2]],
            'notes' => 'private-order-notes',
            'metadata' => ['private' => 'private-order-metadata'],
        ]);
        $body = $this->json($response, 201);
        $uuid = $body['data']['uuid'];
        self::assertSame(2400, $this->order($uuid)->getTotalAmount());
        $this->json($this->request('POST', '/api/v1/app/orders/'.$uuid.'/confirm', $this->ownerToken), 200);
        self::assertSame(Order::STATUS_CONFIRMED, $this->order($uuid)->getStatus());

        return $uuid;
    }

    private function launch(string $orderUuid, array $body = [], ?string $token = null): array
    {
        $this->huifu->responses[] = ['data' => [
            'resp_code' => '00000100', 'resp_desc' => 'Payment accepted', 'trans_stat' => 'P',
            'trans_amt' => '24.00', 'qr_code' => 'https://qr.example.test/customer-payment',
            'pay_info' => ['appId' => 'wx-customer-huifu', 'package' => 'prepay_id=test-only'],
        ]];
        $response = $this->request('POST', $this->paymentPath($orderUuid), $token ?? $this->payerToken, array_replace([
            'payment' => 'huifu', 'gateway' => 'native', 'tradeType' => 'T_NATIVE',
        ], $body));
        $decoded = $this->json($response, 200);
        self::assertSame(0, $decoded['code']);
        self::assertIsString($decoded['message']);
        $data = $decoded['data'];
        self::assertSame(Invoice::STATUS_PAYING, $data['status']);
        self::assertSame('https://qr.example.test/customer-payment', $data['payUrl']);
        self::assertSame($data['payUrl'], $data['qrCode']);
        self::assertArrayHasKey('uuid', $data['invoice']);
        self::assertSame(['attemptUuid', 'channel', 'payInfo', 'reqDate', 'reqSeqId', 'tradeType'], $this->sortedKeys($data['payload']));
        if ($token !== $this->ownerToken) {
            foreach (['outTradeNo', 'status', 'amount', 'currency'] as $field) {
                self::assertArrayHasKey($field, $data['invoice']);
            }
            self::assertSame(2400, $data['invoice']['amount']);
            self::assertSame('CNY', $data['invoice']['currency']);
            $this->assertNoPrivateFields($response);
        }

        return $data;
    }

    private function notify(HuifuPaymentAttempt $attempt, string $status, array $overrides = [], bool $invalidSignature = false): Response
    {
        // Sign the exact raw resp_data bytes, rather than the gateway's sorted request representation.
        $raw = json_encode(array_replace([
            'resp_code' => '00000000', 'resp_desc' => 'Simulated notification',
            'req_seq_id' => $attempt->getReqSeqId(), 'req_date' => $attempt->getReqDate(),
            'huifu_id' => $attempt->getHuifuId(), 'trans_amt' => '24.00', 'currency' => 'CNY',
            'trans_stat' => $status, 'out_trans_id' => 'TEST-TRANSACTION-'.$status,
        ], $overrides), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertTrue(openssl_sign($raw, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return self::$kernel->handle(Request::create(
            '/api/payment/notify/huifu/'.$attempt->getUuid(), 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['resp_code' => '00000000', 'resp_data' => $raw,
                'sign' => $invalidSignature ? base64_encode('invalid-signature') : base64_encode($signature)]),
        ));
    }

    private function request(string $method, string $path, ?string $token = null, ?array $body = null): Response
    {
        return self::$kernel->handle(Request::create($path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_STORE_CODE' => $this->store->getCode(),
            ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
        ], $body === null ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR)));
    }

    private function json(Response $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertNoPrivateFields(Response $response): void
    {
        $body = (string) $response->getContent();
        foreach (['private-', 'BEGIN PRIVATE KEY', 'merchant_private_key', 'credentialsCipher', 'huifuAttemptProof',
            'requestSnapshot', 'responseSnapshot', 'providerProfileUuid', 'merchantUuid', 'payerUuid', 'openid',
            'private-order-notes', 'private-order-metadata', '6666000000000001', '6666000000000101'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
    }

    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    private function paymentPath(string $uuid): string
    {
        return '/api/v1/app/orders/'.$uuid.'/payment';
    }

    private function order(string $uuid): Order
    {
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Order::class, $order);
        $this->em->refresh($order);

        return $order;
    }

    private function invoice(string $uuid): Invoice
    {
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Invoice::class, $invoice);
        $this->em->refresh($invoice);

        return $invoice;
    }

    private function attempt(string $uuid): HuifuPaymentAttempt
    {
        $attempt = $this->em->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(HuifuPaymentAttempt::class, $attempt);
        $this->em->refresh($attempt);

        return $attempt;
    }
}
