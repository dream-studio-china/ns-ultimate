<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use NsUltimate\Business\Wechat\Command\RevokeWechatCredentialCommand;
use NsUltimate\Business\Wechat\Command\RotateWechatKeyCommand;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Service\EasyWeChatPayProvider;
use NsUltimate\Business\Wechat\Service\SecretCipher;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatPaymentGateway;
use NsUltimate\Business\Wechat\Tests\Support\FakeLegacyGateway;
use NsUltimate\Business\Wechat\Tests\Support\FakeWechatPayProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class WechatOperationsTest extends WechatApiTestCase
{
    private FakeWechatPayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeWechatPayProvider();
        self::getContainer()->set(EasyWeChatPayProvider::class, $this->provider);
    }

    public function testLegacyPayDelegatesWithExplicitFlag(): void
    {
        $legacy = new FakeLegacyGateway();
        self::getContainer()->set('App\Wechat\Service\Payment\WechatPayGateway', $legacy);
        [$invoice] = $this->invoiceForStore($this->createStore('LEG'));

        $result = self::getContainer()->get(WechatPaymentGateway::class)
            ->pay($invoice, 100, ['legacy' => true]);

        self::assertSame('legacy pay', $result->message);
        self::assertSame([['method' => 'pay', 'invoice' => $invoice->getUuid(), 'amount' => 100]], $legacy->calls);
    }

    public function testLegacyNotifyDelegatesWithoutLocator(): void
    {
        $legacy = new FakeLegacyGateway();
        self::getContainer()->set('App\Wechat\Service\Payment\WechatPayGateway', $legacy);

        $result = self::getContainer()->get(WechatPaymentGateway::class)
            ->notify(Request::create('/api/payment/notify/wechat', 'POST'));

        self::assertSame('legacy-order', $result->outTradeNo);
        self::assertSame([['method' => 'notify', 'invoice' => '', 'amount' => 0]], $legacy->calls);
    }

    public function testLegacyRefundDelegatesWithExplicitFlag(): void
    {
        $legacy = new FakeLegacyGateway();
        self::getContainer()->set('App\Wechat\Service\Payment\WechatPayGateway', $legacy);
        [$invoice] = $this->invoiceForStore($this->createStore('LEG'));

        $result = self::getContainer()->get(WechatPaymentGateway::class)
            ->refund($invoice, 100, 100, 'legacy reason', ['legacy' => true]);

        self::assertSame('legacy-refund', $result->refundId);
    }

    public function testNewStorePaymentNeverRoutesToLegacy(): void
    {
        [$invoice] = $this->invoiceForStore($this->createStore('NEW'));

        try {
            self::getContainer()->get(WechatPaymentGateway::class)->pay($invoice, 100, []);
            self::fail('Expected fail-closed behavior.');
        } catch (\InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            self::assertStringContainsString('not configured', $e->getMessage());
        }
    }

    public function testRevokeBlocksNewAndHistoricalOperations(): void
    {
        $store = $this->createStore('RVK');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-rvk'), 'secret-rvk');
        $this->createStoreSetting($store->getUuid(), $applicationRevision, null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);

        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        self::assertTrue($resolver->isConfigured($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN));

        $tester = new CommandTester(self::getContainer()->get(RevokeWechatCredentialCommand::class));
        self::assertSame(0, $tester->execute([
            'type' => 'application',
            'uuid' => $applicationRevision,
            '--reason' => 'compromised in test',
        ]));

        self::assertFalse($resolver->isConfigured($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN));
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM wechat_audit_event WHERE action = 'revoke'",
        ));

        // Binding a revoked revision is rejected as well.
        $storeB = $this->createStore('RVK2');
        [$status] = $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $storeB->getUuid(),
            'miniappRevision' => $applicationRevision,
            'capabilities' => [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        ]);
        self::assertSame(400, $status);
    }

    public function testRotateKeyPreservesDecryption(): void
    {
        $store = $this->createStore('ROT');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-rot'), 'secret-rot');
        $this->createStoreSetting($store->getUuid(), $applicationRevision, null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);

        $newKey = base64_encode(random_bytes(32));
        $tester = new CommandTester(self::getContainer()->get(RotateWechatKeyCommand::class));

        self::assertSame(0, $tester->execute(['--new-key' => $newKey, '--dry-run' => true]));
        $before = $this->entityManager()->getConnection()->fetchOne(
            'SELECT secret_encrypted FROM wechat_application_revision WHERE uuid = ?',
            [$applicationRevision],
        );

        self::assertSame(0, $tester->execute(['--new-key' => $newKey]));
        $after = $this->entityManager()->getConnection()->fetchOne(
            'SELECT secret_encrypted FROM wechat_application_revision WHERE uuid = ?',
            [$applicationRevision],
        );
        self::assertNotSame($before, $after);

        // The stored payload decrypts under the replacement key.
        self::assertSame('secret-rot', (new SecretCipher($newKey))->decrypt((string) $after));

        // Resolution still works after rotation when the container uses the new key.
        $this->entityManager()->clear();
        $resolver = new StoreWechatResolver($this->entityManager(), new SecretCipher($newKey));
        self::assertSame('secret-rot', $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN)->appSecret);
    }

    public function testStoreRefundEndpointEnforcesMembershipAndGrants(): void
    {
        $storeA = $this->createStore('A');
        $storeB = $this->createStore('B');
        [$invoiceA] = $this->paidInvoiceForStore($storeA);

        $operator = $this->storeUser($storeA, ['store:wechat:refund', 'store:wechat:read']);
        $tokenA = self::getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($operator);

        // Cross-store access is denied.
        [$status] = $this->request(
            'POST',
            '/api/v1/store/'.$storeB->getUuid().'/wechat-refunds/request',
            ['invoiceUuid' => $invoiceA->getUuid(), 'amount' => 100],
            $tokenA,
        );
        self::assertSame(403, $status);

        // A user without the refund permission is denied on their own store.
        $reader = $this->storeUser($storeA, ['store:wechat:read']);
        $tokenReader = self::getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($reader);
        [$status] = $this->request(
            'POST',
            '/api/v1/store/'.$storeA->getUuid().'/wechat-refunds/request',
            ['invoiceUuid' => $invoiceA->getUuid(), 'amount' => 100],
            $tokenReader,
        );
        self::assertSame(403, $status);

        // The authorized operator succeeds.
        [$status, $body] = $this->request(
            'POST',
            '/api/v1/store/'.$storeA->getUuid().'/wechat-refunds/request',
            ['invoiceUuid' => $invoiceA->getUuid(), 'amount' => 100, 'idempotencyKey' => 'store-refund-1'],
            $tokenA,
        );
        self::assertSame(201, $status, json_encode($body));

        // The operator sees only their own store's refunds in the list.
        [$status, $body] = $this->request('GET', '/api/v1/store/'.$storeA->getUuid().'/wechat-refunds', null, $tokenA);
        self::assertSame(200, $status, json_encode($body));
        self::assertNotEmpty($body['data']);
    }

    public function testStoreBindingRequiresProfileGrant(): void
    {
        $store = $this->createStore('G');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-g'), 'secret-g');

        [$status] = $this->request('POST', '/api/v1/store/'.$store->getUuid().'/wechat-setting', [
            'miniappRevision' => $applicationRevision,
            'capabilities' => [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        ], $this->storeToken($store, ['store:wechat:create', 'store:wechat:read']));
        self::assertSame(400, $status);

        $application = $this->entityManager()->getRepository(\NsUltimate\Business\Wechat\Entity\WechatApplication::class)
            ->findOneBy(['appId' => 'wx-mini-g']);
        [$status] = $this->request('POST', '/api/v1/manage/wechat-profile-grants', [
            'profileType' => 'application',
            'profileUuid' => $application?->getUuid(),
            'storeUuid' => $store->getUuid(),
        ]);
        self::assertSame(201, $status);

        [$status] = $this->request('POST', '/api/v1/store/'.$store->getUuid().'/wechat-setting', [
            'miniappRevision' => $applicationRevision,
            'capabilities' => [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        ], $this->storeToken($store, ['store:wechat:create', 'store:wechat:read']));
        self::assertSame(201, $status);
    }

    /** @param list<string> $permissions */
    private function storeUser(\App\Store\Entity\Store $store, array $permissions): User
    {
        $entityManager = $this->entityManager();
        $user = (new User())
            ->setEmail('op-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('op-'.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_USER']);
        $entityManager->persist($user);
        $entityManager->flush();

        // A dedicated role per caller keeps permission sets isolated: reusing a
        // shared role would leak other callers' permissions into this user.
        $role = new Role('store_wechat_test_'.bin2hex(random_bytes(4)), 'Store WeChat Test', Role::SCOPE_STORE);
        $entityManager->persist($role);
        foreach ($permissions as $code) {
            $permission = $entityManager->getRepository(Permission::class)->findOneBy(['code' => $code]);
            if (!$permission instanceof Permission) {
                $permission = new Permission($code, 'store', 'wechat', 'refund', $code);
                $entityManager->persist($permission);
            }
            $role->addPermission($permission);
        }
        $entityManager->persist(new Assignment($role, $user->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()));
        $entityManager->flush();

        return $user;
    }

    /** @param list<string> $permissions */
    private function storeToken(\App\Store\Entity\Store $store, array $permissions): string
    {
        return self::getContainer()->get(\App\Identity\Security\TokenManager::class)
            ->createAccessToken($this->storeUser($store, $permissions));
    }

    /** @return array{0: Invoice} */
    private function invoiceForStore(\App\Store\Entity\Store $store): array    {
        $entityManager = $this->entityManager();
        $order = new Order();
        $entityManager->persist($order);
        $entityManager->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));

        $invoice = new Invoice();
        $invoice->setSourceType('trade_order');
        $invoice->setSourceId($order->getUuid());
        $invoice->setAmount(1000);
        $entityManager->persist($invoice);
        $entityManager->flush();

        return [$invoice];
    }

    /** @return array{0: Invoice} */
    private function paidInvoiceForStore(\App\Store\Entity\Store $store): array
    {
        $this->createStoreSetting(
            $store->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-'.$store->getCode()), 'secret-'.$store->getCode()),
            $this->publishMerchantRevision($this->createMerchant('1900000001')),
            [
                StoreWechatSetting::CAP_PAYMENT_JSAPI => true,
                StoreWechatSetting::CAP_PAYMENT_NATIVE => true,
            ],
        );

        $entityManager = $this->entityManager();
        $payer = (new User())
            ->setEmail('payer-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('payer-'.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_USER']);
        $entityManager->persist($payer);
        $entityManager->persist(
            (new \NsUltimate\Business\Wechat\Entity\WechatIdentity())
                ->setUser($payer)
                ->setAppId('wx-mini-'.$store->getCode())
                ->setApplicationType('miniapp')
                ->setOpenid('openid-'.$store->getCode()),
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

        self::getContainer()->get(\NsUltimate\Business\Wechat\Service\WechatPaymentGateway::class)
            ->pay($invoice, 1000, ['tradeType' => WechatPaymentAttempt::TRADE_NATIVE]);
        $attempt = $entityManager->getRepository(WechatPaymentAttempt::class)->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertInstanceOf(WechatPaymentAttempt::class, $attempt);
        self::getContainer()->get(\NsUltimate\Business\Wechat\Service\WechatPaymentCompletionService::class)->confirmFromProviderData($attempt, [
            'out_trade_no' => $attempt->getProviderOrderNo(),
            'transaction_id' => 'wx-txn-1',
            'trade_state' => 'SUCCESS',
            'mchid' => '1900000001',
            'appid' => 'wx-mini-'.$store->getCode(),
            'amount' => ['total' => 1000, 'currency' => 'CNY'],
        ], 'test');
        $entityManager->refresh($invoice);

        return [$invoice];
    }

    public function testZDebugVoter(): void
    {
        $store = $this->createStore('D');
        $em = $this->entityManager();
        $user = (new User())
            ->setEmail('dbg@example.test')
            ->setUsername('dbguser')
            ->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
        $role = new Role('store_wechat_manager', 'Store WeChat Manager', Role::SCOPE_STORE);
        $em->persist($role);
        $permission = new Permission('store:wechat:refund', 'store', 'wechat', 'refund', 'refund');
        $em->persist($permission);
        $role->addPermission($permission);
        $em->persist(new Assignment($role, $user->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()));
        $em->flush();

        $service = self::getContainer()->get(\App\Authorization\Service\AuthorizationServiceInterface::class);
        $scope = \App\Authorization\Service\AuthorizationScope::store($store->getUuid());
        file_put_contents('/tmp/dbg_voter.txt', 'CAN='.var_export($service->can($user, 'store:wechat:refund', $scope), true)."\n");
        $found = $em->getRepository(Assignment::class)->findBy(['userUuid' => $user->getUuid()]);
        file_put_contents('/tmp/dbg_voter.txt', 'ASSIGNMENTS='.count($found)."\n", FILE_APPEND);
        self::assertTrue(true);
    }
}

