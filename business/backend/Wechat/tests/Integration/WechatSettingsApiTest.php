<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\Entity\Invoice;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Wechat\Service\WechatServiceInterface;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatIdentity;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\StoreAwareWechatService;
use NsUltimate\Business\Wechat\Service\StoreWechatAuthService;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatSecretCipher;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;

final class WechatSettingsApiTest extends KernelTestCase
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

    public function testAdminCreatesEncryptedProfilesAndStoreManagersSelectThem(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $admin = (new User())->setEmail('wechat-admin@example.test')->setUsername('wechat-admin')->setRoles(['ROLE_ADMIN']);
        $owner = (new User())->setEmail('wechat-owner@example.test')->setUsername('wechat-owner');
        $store = new Store('wechat-store', 'WeChat Store');
        foreach ([$admin, $owner, $store] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new Membership($store, $owner->getUuid(), Membership::ROLE_OWNER));
        $em->flush();

        $adminToken = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $ownerToken = self::getContainer()->get(TokenManager::class)->createAccessToken($owner);
        $app = $this->request('POST', '/api/v1/manage/wechat-applications', $adminToken, [
            'name' => 'Mini app', 'type' => 'miniapp', 'appId' => 'wx-mini-test', 'secret' => 'application-secret',
        ]);
        $official = $this->request('POST', '/api/v1/manage/wechat-applications', $adminToken, [
            'name' => 'Official account', 'type' => 'official', 'appId' => 'wx-official-test', 'secret' => 'official-secret',
            'token' => 'official-token', 'aesKey' => 'official-aes-key',
        ]);
        $merchant = $this->request('POST', '/api/v1/manage/wechat-merchants', $adminToken, [
            'name' => 'Merchant', 'merchantId' => '1900000001', 'apiV3Key' => str_repeat('a', 32),
            'privateKey' => 'merchant-private-key', 'certificate' => 'merchant-certificate',
        ]);

        self::assertStringNotContainsString('application-secret', json_encode($app, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('credentialsCipher', json_encode($app, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('merchant-private-key', json_encode($merchant, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('credentialsCipher', json_encode($merchant, JSON_THROW_ON_ERROR));
        $selection = $this->request('PUT', '/api/v1/store/'.$store->getUuid().'/wechat-setting', $ownerToken, [
            'miniappUuid' => $app['data']['uuid'],
            'officialUuid' => $official['data']['uuid'],
            'merchantUuid' => $merchant['data']['uuid'],
        ]);
        self::assertSame($store->getUuid(), $selection['data']['storeUuid']);
        self::assertSame($app['data']['uuid'], $selection['data']['miniappUuid']);
        self::assertSame($official['data']['uuid'], $selection['data']['officialUuid']);

        $this->request('PUT', '/api/v1/manage/wechat-applications/'.$app['data']['id'], $adminToken, ['secret' => 'rotated-secret']);
        $detail = $this->request('GET', '/api/v1/manage/wechat-applications/'.$app['data']['id'], $adminToken);
        self::assertSame('wx-mini-test', $detail['data']['appId']);
        self::assertArrayNotHasKey('secret', $detail['data']);

        $storedApplication = $em->getRepository(WechatApplication::class)->findOneBy(['uuid' => $app['data']['uuid']]);
        $storedMerchant = $em->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $merchant['data']['uuid']]);
        self::assertSame('rotated-secret', $storedApplication->decryptCredentials($cipher)['secret']);
        self::assertSame('merchant-private-key', $storedMerchant->decryptCredentials($cipher)['private_key']);
        self::assertInstanceOf(StoreWechatSetting::class, $em->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $store->getUuid()]));
    }

    public function testStoreWechatGatewayOverridesOnlyTheCoreWechatRegistration(): void
    {
        $registry = self::getContainer()->get(PaymentGatewayRegistry::class);
        self::assertInstanceOf(\NsUltimate\Business\Wechat\Service\StoreWechatPaymentGateway::class, $registry->get(Invoice::PAYMENT_WECHAT));
        self::assertContains(Invoice::PAYMENT_MOCK, $registry->names());
    }

    public function testNonMemberCannotReadStoreSettings(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('wechat-outsider@example.test')->setUsername('wechat-outsider');
        $store = new Store('wechat-private', 'Private Store');
        $em->persist($user);
        $em->persist($store);
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);

        $request = Request::create('/api/v1/store/'.$store->getUuid().'/wechat-setting', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $response = self::$kernel->handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testWechatServiceSelectsClientsFromCurrentStoreWithoutMutableGlobalState(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $merchant = new WechatMerchant('Merchant', '1900000002', $cipher->encrypt([
            'api_v3_key' => str_repeat('b', 32), 'private_key' => 'key', 'certificate' => 'cert',
        ]));
        $storeA = new Store('wechat-runtime-a', 'Runtime A');
        $storeB = new Store('wechat-runtime-b', 'Runtime B');
        $miniA = new WechatApplication('Mini A', WechatApplication::TYPE_MINIAPP, 'wx-runtime-a', $cipher->encrypt(['secret' => 'secret-a']));
        $miniB = new WechatApplication('Mini B', WechatApplication::TYPE_MINIAPP, 'wx-runtime-b', $cipher->encrypt(['secret' => 'secret-b']));
        foreach ([$merchant, $storeA, $storeB, $miniA, $miniB] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new StoreWechatSetting($storeA->getUuid(), $miniA, null, $merchant));
        $em->persist(new StoreWechatSetting($storeB->getUuid(), $miniB, null, $merchant));
        $em->flush();

        $requestStack = self::getContainer()->get(RequestStack::class);
        $service = self::getContainer()->get(StoreAwareWechatService::class);
        $requestStack->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeA->getUuid()], JSON_THROW_ON_ERROR)));
        self::assertSame('wx-runtime-a', $service->getMiniApp()->getAccount()->getAppId());
        $requestStack->pop();
        $requestStack->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeB->getUuid()], JSON_THROW_ON_ERROR)));
        self::assertSame('wx-runtime-b', $service->getMiniApp()->getAccount()->getAppId());
        $requestStack->pop();
    }

    public function testSameOpenidOnDifferentAppIdsCreatesSeparateUsers(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $merchant = new WechatMerchant('Merchant', '1900000003', $cipher->encrypt([
            'api_v3_key' => str_repeat('c', 32), 'private_key' => 'key', 'certificate' => 'cert',
        ]));
        $storeA = new Store('wechat-login-a', 'Login A');
        $storeB = new Store('wechat-login-b', 'Login B');
        $miniA = new WechatApplication('Mini A', WechatApplication::TYPE_MINIAPP, 'wx-login-a', $cipher->encrypt(['secret' => 'a']));
        $miniB = new WechatApplication('Mini B', WechatApplication::TYPE_MINIAPP, 'wx-login-b', $cipher->encrypt(['secret' => 'b']));
        foreach ([$merchant, $storeA, $storeB, $miniA, $miniB] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new StoreWechatSetting($storeA->getUuid(), $miniA, null, $merchant));
        $em->persist(new StoreWechatSetting($storeB->getUuid(), $miniB, null, $merchant));
        $em->flush();

        $requests = new RequestStack();
        $service = new StoreWechatAuthService(new FakeWechatService(), self::getContainer()->get(StoreWechatResolver::class), $em, $requests);
        $requests->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeA->getUuid()], JSON_THROW_ON_ERROR)));
        $userA = $service->authenticateFromMiniApp('same-openid-code');
        $sameAppUser = $service->authenticateFromMiniApp('same-openid-code');
        $requests->pop();
        $requests->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeB->getUuid()], JSON_THROW_ON_ERROR)));
        $userB = $service->authenticateFromMiniApp('same-openid-code');
        $requests->pop();

        self::assertNotSame($userA->getId(), $userB->getId());
        self::assertSame($userA->getId(), $sameAppUser->getId());
        self::assertSame(2, $em->getRepository(StoreWechatIdentity::class)->count(['openid' => 'same-openid']));
    }

    /** @param array<string, mixed>|null $data
     * @return array<string, mixed> */
    private function request(string $method, string $path, string $token, ?array $data = null): array
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::assertLessThan(300, $response->getStatusCode(), $response->getContent());
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class FakeWechatService implements WechatServiceInterface
{
    public function getMiniApp(): MiniApp { throw new \LogicException('Not used by this test.'); }
    public function getOfficialAccount(): OfficialAccount { throw new \LogicException('Not used by this test.'); }
    public function getPayApp(): Pay { throw new \LogicException('Not used by this test.'); }
    public function setMiniApp(MiniApp $app): void {}
    public function setOfficialAccount(OfficialAccount $app): void {}
    public function setPayApp(Pay $app): void {}
    public function code2Session(string $jsCode): array { return ['openid' => 'same-openid', 'session_key' => 'discarded']; }
    public function getPhoneNumber(string $code): array { return ['phoneNumber' => '']; }
    public function getOAuthRedirectUrl(string $callbackUrl): string { return $callbackUrl; }
    public function getOAuthUser(string $code): array { return ['openid' => 'same-openid', 'nickname' => '', 'avatar' => '', 'sex' => 0, 'province' => '', 'city' => '', 'country' => '']; }
}
