<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Identity\Entity\User;
use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Identity\Security\TokenManager;
use App\Wechat\Entity\WechatUser;
use App\Payment\Entity\Invoice;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Wechat\Service\WechatServiceInterface;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
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
use Symfony\Component\Console\Tester\CommandTester;
use NsUltimate\Business\Wechat\Command\SeedWechatAuthorizationCommand;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;
use EasyWeChat\Kernel\Support\AesGcm;

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
        $adminNoMerchantStore = new Store('wechat-admin-no-merchant', 'Admin Store Without Merchant');
        $ownerNoMerchantStore = new Store('wechat-owner-no-merchant', 'Owner Store Without Merchant');
        foreach ([$admin, $owner, $store, $adminNoMerchantStore, $ownerNoMerchantStore] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new Membership($store, $owner->getUuid(), Membership::ROLE_OWNER));
        $em->persist(new Membership($ownerNoMerchantStore, $owner->getUuid(), Membership::ROLE_OWNER));
        $seed = new CommandTester(new SeedWechatAuthorizationCommand($em));
        self::assertSame(0, $seed->execute([]));
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'store_wechat_manager']);
        $em->persist(new Assignment($role, $owner->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()));
        $em->persist(new Assignment($role, $owner->getUuid(), Assignment::SCOPE_STORE, $ownerNoMerchantStore->getUuid()));
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
            'privateKey' => 'merchant-private-key', 'certificate' => 'merchant-certificate', 'platformCertificate' => $this->testCertificate(),
        ]);

        foreach ([
            ['/api/v1/manage/wechat-applications?@display=reduce', $app, 'Mini app'],
            ['/api/v1/manage/wechat-merchants?@display=reduce', $merchant, 'Merchant'],
        ] as [$path, $profile, $expectedLabel]) {
            $reduced = $this->request('GET', $path, $adminToken);
            $option = array_values(array_filter(
                $reduced['data'],
                static fn (array $row): bool => $row['uuid'] === $profile['data']['uuid'],
            ))[0] ?? null;
            self::assertIsArray($option);
            self::assertSame($expectedLabel, $option['__toString']);
        }

        self::assertStringNotContainsString('application-secret', json_encode($app, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('credentialsCipher', json_encode($app, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('merchant-private-key', json_encode($merchant, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('credentialsCipher', json_encode($merchant, JSON_THROW_ON_ERROR));

        $adminSettingWithoutMerchant = $this->request('POST', '/api/v1/manage/store-wechat-settings', $adminToken, [
            'storeUuid' => $adminNoMerchantStore->getUuid(),
            'miniappUuid' => $app['data']['uuid'],
        ]);
        self::assertNull($adminSettingWithoutMerchant['data']['merchantUuid']);

        $selection = $this->request('POST', '/api/v1/store/'.$store->getUuid().'/wechat-settings', $ownerToken, [
            'miniappUuid' => $app['data']['uuid'],
            'officialUuid' => $official['data']['uuid'],
            'merchantUuid' => $merchant['data']['uuid'],
        ]);
        self::assertSame($store->getUuid(), $selection['data']['storeUuid']);
        self::assertSame($app['data']['uuid'], $selection['data']['miniappUuid']);
        self::assertSame($official['data']['uuid'], $selection['data']['officialUuid']);

        $ownerSettingWithoutMerchant = $this->request('POST', '/api/v1/store/'.$ownerNoMerchantStore->getUuid().'/wechat-settings', $ownerToken, [
            'miniappUuid' => $app['data']['uuid'],
        ]);
        self::assertNull($ownerSettingWithoutMerchant['data']['merchantUuid']);

        $storeSettingId = $selection['data']['id'];
        $updated = $this->request('PUT', '/api/v1/store/'.$store->getUuid().'/wechat-settings/'.$storeSettingId, $ownerToken, [
            'officialUuid' => null,
            'merchantUuid' => null,
        ]);
        self::assertNull($updated['data']['officialUuid']);
        self::assertNull($updated['data']['merchantUuid']);
        self::assertSame($store->getUuid(), $updated['data']['storeUuid']);

        $options = $this->request('GET', '/api/v1/store/'.$store->getUuid().'/wechat-settings/options', $ownerToken);
        self::assertCount(2, $options['applications']);
        self::assertCount(1, $options['merchants']);

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

    public function testPublicOAuthUrlUsesTheRequestedStoreThroughTheContainer(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $callbackUrl = 'https://example.test/order?counterId=1&ns_oauth=test';

        foreach (['a', 'b'] as $suffix) {
            $store = new Store('wechat-oauth-'.$suffix, 'OAuth Store '.$suffix);
            $official = new WechatApplication(
                'OAuth Official '.$suffix,
                WechatApplication::TYPE_OFFICIAL,
                'wx-oauth-'.$suffix,
                $cipher->encrypt(['secret' => 'oauth-test-secret']),
            );
            $em->persist($store);
            $em->persist($official);
            $em->persist(new StoreWechatSetting($store->getUuid(), null, $official));
            $em->flush();

            $request = Request::create('/api/wechat/oauth/url', 'GET', [
                'store_uuid' => $store->getUuid(),
                'redirect_uri' => $callbackUrl,
            ]);
            $response = self::$kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('open.weixin.qq.com', parse_url($payload['url'], PHP_URL_HOST));
            parse_str(parse_url($payload['url'], PHP_URL_QUERY), $query);
            self::assertSame($official->getAppId(), $query['appid']);
            self::assertSame($callbackUrl, $query['redirect_uri']);
            self::assertSame('snsapi_userinfo', $query['scope']);
            self::assertSame('code', $query['response_type']);
        }
    }

    public function testStoreWechatGatewayOverridesOnlyTheCoreWechatRegistration(): void
    {
        $registry = self::getContainer()->get(PaymentGatewayRegistry::class);
        self::assertInstanceOf(\NsUltimate\Business\Wechat\Service\StoreWechatPaymentGateway::class, $registry->get(Invoice::PAYMENT_WECHAT));
        self::assertContains(Invoice::PAYMENT_MOCK, $registry->names());
    }

    public function testAdminCanConfigureImmutableServiceProviderMerchantProfile(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('wechat-provider-admin@example.test')->setUsername('wechat-provider-admin')->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $provider = $this->request('POST', '/api/v1/manage/wechat-merchants', $token, [
            'name' => 'Service provider',
            'merchantId' => '1900000100',
            'paymentMode' => WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER,
            'serviceProviderAppId' => ' wx-provider-app ',
            'subMerchantId' => ' 1900000101 ',
            'apiV3Key' => str_repeat('p', 32),
            'privateKey' => 'provider-private-key',
            'certificate' => 'provider-merchant-certificate',
            'platformCertificate' => $this->testCertificate(),
        ]);
        self::assertSame(WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER, $provider['data']['paymentMode']);
        self::assertSame('wx-provider-app', $provider['data']['serviceProviderAppId']);
        self::assertSame('1900000101', $provider['data']['subMerchantId']);
        self::assertArrayNotHasKey('credentialsCipher', $provider['data']);

        $secondSubMerchant = $this->request('POST', '/api/v1/manage/wechat-merchants', $token, [
            'name' => 'Second service-provider sub-merchant',
            'merchantId' => '1900000100',
            'paymentMode' => WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER,
            'serviceProviderAppId' => 'wx-provider-app',
            'subMerchantId' => '1900000102',
            'apiV3Key' => str_repeat('p', 32),
            'privateKey' => 'provider-private-key',
            'certificate' => 'provider-merchant-certificate',
            'platformCertificate' => $this->testCertificate(),
        ]);
        self::assertSame('1900000100', $secondSubMerchant['data']['merchantId']);
        self::assertSame('1900000102', $secondSubMerchant['data']['subMerchantId']);
        self::assertNotSame($provider['data']['id'], $secondSubMerchant['data']['id']);

        $request = Request::create('/api/v1/manage/wechat-merchants/'.$provider['data']['id'], 'PUT', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['subMerchantId' => '1900000102'], JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), $response->getContent());

        $stored = $em->getRepository(WechatMerchant::class)->find($provider['data']['id']);
        self::assertSame('1900000101', $stored->getSubMerchantId());
    }

    public function testMerchantProfileConstraintRejectsAnExactServiceProviderDuplicate(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = (new WechatMerchant('Provider one', '1900000200'))
            ->setPaymentMode(WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER)
            ->setServiceProviderAppId('wx-provider')
            ->setSubMerchantId('1900000201');
        $em->persist($first);
        $em->flush();

        $duplicate = (new WechatMerchant('Provider duplicate', '1900000200'))
            ->setPaymentMode(WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER)
            ->setServiceProviderAppId('wx-provider')
            ->setSubMerchantId('1900000201');
        $em->persist($duplicate);
        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testMerchantProfileConstraintKeepsDirectMerchantIdsUnique(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new WechatMerchant('Direct one', '1900000300'));
        $em->flush();
        $em->persist(new WechatMerchant('Direct duplicate', '1900000300'));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testPaymentCallbackRejectsMissingSignatureHeaders(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $store = new Store('wechat-callback-store', 'Callback store');
        $application = new WechatApplication(
            'Callback mini app',
            WechatApplication::TYPE_MINIAPP,
            'wx-callback-app',
            $cipher->encrypt(['secret' => 'callback-secret']),
        );
        $merchant = new WechatMerchant('Callback merchant', '1900000400', $cipher->encrypt([
            'api_v3_key' => str_repeat('k', 32),
            'private_key' => 'test-private-key',
            'certificate' => 'test-merchant-certificate',
            'platform_certificate' => $this->testCertificate(),
        ]));
        foreach ([$store, $application, $merchant] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $key = str_repeat('k', 32);
        $nonce = '0123456789ab';
        $resource = [
            'appid' => $application->getAppId(),
            'mchid' => $merchant->getMerchantId(),
            'out_trade_no' => 'callback-order',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 100, 'currency' => 'CNY'],
        ];
        $body = json_encode([
            'event_type' => 'TRANSACTION.SUCCESS',
            'resource' => [
                'algorithm' => 'AEAD_AES_256_GCM',
                'ciphertext' => AesGcm::encrypt(json_encode($resource, JSON_THROW_ON_ERROR), $key, $nonce),
                'nonce' => $nonce,
                'associated_data' => '',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/payment/notify/wechat-store/'.$store->getUuid().'/'.$application->getUuid().'/'.$merchant->getUuid().'/miniapp',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        );

        $response = self::$kernel->handle($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['code' => 'FAIL', 'message' => 'Notification processing failed.'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testServiceProviderMessagesAreTranslatedInSupportedLocales(): void
    {
        $translator = self::getContainer()->get('translator');
        foreach ([
            'en' => ['Service provider', 'Sub-merchant ID is required for service-provider payments.'],
            'zh' => ['服务商', '服务商模式必须填写子商户号。'],
            'zh_Hant' => ['服務商', '服務商模式必須填寫子商戶號。'],
            'ja' => ['サービスプロバイダー', 'サービスプロバイダーモードではサブ加盟店番号が必要です。'],
        ] as $locale => [$mode, $error]) {
            self::assertSame($mode, $translator->trans('Service provider', [], 'messages', $locale));
            self::assertSame($error, $translator->trans('Sub-merchant ID is required for service-provider payments.', [], 'messages', $locale));
        }
    }

    public function testEveryRegisteredEntityFieldHasBackendTranslations(): void
    {
        $metadataFactory = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory();
        $translator = self::getContainer()->get('translator');
        foreach ($metadataFactory->getAllMetadata() as $metadata) {
            $fields = array_merge(array_keys($metadata->fieldMappings), array_keys($metadata->associationMappings));
            foreach ($fields as $field) {
                $parts = preg_split('/(?=[A-Z])/', $field);
                $plainText = ucfirst(strtolower(ucwords(implode(' ', is_array($parts) ? $parts : [$field]))));
                self::assertTrue(
                    $translator->getCatalogue('en')->defines($plainText),
                    sprintf('Missing en translation for %s::$%s (%s).', $metadata->getName(), $field, $plainText),
                );
                foreach (['zh', 'zh_Hant', 'ja'] as $locale) {
                    self::assertNotSame(
                        $plainText,
                        $translator->trans($plainText, [], 'messages', $locale),
                        sprintf('Missing %s translation for %s::$%s (%s).', $locale, $metadata->getName(), $field, $plainText),
                    );
                }
            }
        }
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

        $request = Request::create('/api/v1/store/'.$store->getUuid().'/wechat-settings', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $response = self::$kernel->handle($request);
        self::assertSame(403, $response->getStatusCode());

        $request = Request::create('/api/v1/store/'.$store->getUuid().'/wechat-settings', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $response = self::$kernel->handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testStoreSettingsRequireScopedPermissionsAndActiveMembership(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seed = new CommandTester(new SeedWechatAuthorizationCommand($em));
        self::assertSame(0, $seed->execute([]));
        self::assertSame(0, $seed->execute([]));
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'store_wechat_manager']);
        self::assertInstanceOf(Role::class, $role);
        self::assertCount(4, $role->getPermissions());
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            self::assertTrue($role->hasPermission('store:wechat_setting:'.$action));
        }

        $store = new Store('wechat-auth-a', 'Authorization A');
        $otherStore = new Store('wechat-auth-b', 'Authorization B');
        $app = new WechatApplication('Auth mini', 'miniapp', 'wx-auth', 'encrypted');
        $merchant = new WechatMerchant('Auth merchant', '1900000999', 'encrypted');
        $readerRole = new Role('wechat_test_reader', 'WeChat Reader', Role::SCOPE_STORE);
        $readerRole->addPermission($em->getRepository(Permission::class)->findOneBy(['code' => 'store:wechat_setting:read']));
        foreach ([$store, $otherStore, $app, $merchant, $readerRole] as $entity) {
            $em->persist($entity);
        }
        $users = [];
        foreach (['manager', 'reader', 'unassigned', 'nonmember', 'inactive', 'wrong_scope'] as $kind) {
            $users[$kind] = (new User())->setEmail($kind.'@wechat-auth.test')->setUsername('wechat-auth-'.$kind);
            $em->persist($users[$kind]);
        }
        $em->flush();
        foreach ($users as $kind => $user) {
            if ($kind !== 'nonmember') {
                $membership = new Membership($store, $user->getUuid(), Membership::ROLE_MANAGER);
                if ($kind === 'inactive') {
                    $membership->suspend();
                }
                $em->persist($membership);
            }
            if ($kind !== 'unassigned') {
                $em->persist(new Assignment($kind === 'reader' ? $readerRole : $role, $user->getUuid(), Assignment::SCOPE_STORE, $kind === 'wrong_scope' ? $otherStore->getUuid() : $store->getUuid()));
            }
        }
        $otherSetting = new StoreWechatSetting($otherStore->getUuid(), $app, null, $merchant);
        $em->persist($otherSetting);
        $em->flush();
        $base = '/api/v1/store/'.$store->getUuid().'/wechat-settings';
        $tokens = [];
        foreach ($users as $kind => $user) {
            $tokens[$kind] = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        }
        $send = function (string $kind, string $method, string $path, array $body = []) use ($tokens) {
            return self::$kernel->handle(Request::create($path, $method, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$tokens[$kind],
                'CONTENT_TYPE' => 'application/json',
            ], json_encode($body, JSON_THROW_ON_ERROR)));
        };
        foreach (['unassigned', 'nonmember', 'inactive', 'wrong_scope'] as $kind) {
            foreach ([$base, $base.'/options'] as $path) {
                self::assertSame(403, $send($kind, 'GET', $path)->getStatusCode(), $kind);
            }
            self::assertSame(403, $send($kind, 'POST', $base)->getStatusCode(), $kind);
        }
        self::assertSame(200, $send('reader', 'GET', $base)->getStatusCode());
        self::assertSame(200, $send('reader', 'GET', $base.'/options')->getStatusCode());
        self::assertSame(403, $send('reader', 'POST', $base)->getStatusCode());
        $created = $send('manager', 'POST', $base, ['miniappUuid' => $app->getUuid(), 'merchantUuid' => $merchant->getUuid(), 'storeUuid' => $otherStore->getUuid()]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $payload = json_decode($created->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($store->getUuid(), $payload['data']['storeUuid']);
        $path = $base.'/'.$payload['data']['id'];
        self::assertSame(200, $send('reader', 'GET', $path)->getStatusCode());
        self::assertSame(403, $send('reader', 'PUT', $path)->getStatusCode());
        self::assertSame(403, $send('reader', 'DELETE', $path)->getStatusCode());
        foreach (['unassigned', 'nonmember', 'inactive', 'wrong_scope'] as $kind) {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                self::assertSame(403, $send($kind, $method, $path)->getStatusCode(), $kind);
            }
        }
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            self::assertSame(404, $send('manager', $method, $base.'/'.$otherSetting->getId())->getStatusCode());
            self::assertSame(404, $send('manager', $method, $base.'/'.$otherSetting->getUuid())->getStatusCode());
        }
        self::assertSame(200, $send('manager', 'PUT', $path, ['merchantUuid' => $merchant->getUuid()])->getStatusCode());
        self::assertSame(204, $send('manager', 'DELETE', $path)->getStatusCode());
    }

    public function testAdminStoreSettingsListHonorsStoreFilterAndPagination(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('wechat-list-admin@example.test')->setUsername('wechat-list-admin')->setRoles(['ROLE_ADMIN']);
        $storeA = new Store('wechat-list-a', 'List A');
        $storeB = new Store('wechat-list-b', 'List B');
        $merchant = new WechatMerchant('List merchant', '1900000091', 'encrypted');
        $application = new WechatApplication('List mini', WechatApplication::TYPE_MINIAPP, 'wx-list', 'encrypted');
        foreach ([$admin, $storeA, $storeB, $merchant, $application] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new StoreWechatSetting($storeA->getUuid(), $application, null, $merchant));
        $em->persist(new StoreWechatSetting($storeB->getUuid(), $application, null, $merchant));
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);

        $request = Request::create('/api/v1/manage/store-wechat-settings?page=1&limit=1', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $request->query->set('@filter', "(entity.getStoreUuid() matches '".$storeA->getUuid()."')");
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['data']);
        self::assertSame($storeA->getUuid(), $payload['data'][0]['storeUuid']);
        self::assertSame(1, $payload['paginator']['total']);
        self::assertSame(1, $payload['paginator']['limit']);

        $fragment = substr($storeA->getUuid(), 0, 35);
        self::assertStringNotContainsString($fragment, $storeB->getUuid());
        $request->query->set('@filter', "(entity.getStoreUuid() matches '".$fragment."')");
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['data']);
        self::assertSame($storeA->getUuid(), $payload['data'][0]['storeUuid']);
        self::assertSame(1, $payload['paginator']['total']);

        foreach (['%', '_', '!'] as $literal) {
            $request->query->set('@filter', "(entity.getStoreUuid() matches '".$literal."')");
            $response = self::$kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $payload['data']);
            self::assertSame(0, $payload['paginator']['total']);
        }
    }

    public function testMatchingLegacyWechatIdentityIsLinkedInsteadOfCreatingAnotherUser(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(WechatSecretCipher::class);
        $store = new Store('wechat-legacy-store', 'Legacy store');
        $merchant = new WechatMerchant('Legacy merchant', '1900000092', $cipher->encrypt(['api_v3_key' => 'x']));
        $application = new WechatApplication('Legacy mini', WechatApplication::TYPE_MINIAPP, 'wx-legacy-app', $cipher->encrypt(['secret' => 'x']));
        $user = (new User())->setEmail('legacy-user@example.test')->setUsername('legacy-user');
        $legacy = new WechatUser($user, 'same-openid', WechatUser::APP_TYPE_MINIAPP);
        foreach ([$store, $merchant, $application, $user, $legacy] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new StoreWechatSetting($store->getUuid(), $application, null, $merchant));
        $em->flush();

        $requests = new RequestStack();
        $service = new StoreWechatAuthService(
            new FakeWechatService(), self::getContainer()->get(StoreWechatResolver::class), $em, $requests,
            self::getContainer()->get(\App\Wechat\Repository\WechatUserRepository::class), 'wx-legacy-app', '',
        );
        $requests->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $store->getUuid()], JSON_THROW_ON_ERROR)));
        $authenticatedUser = $service->authenticateFromMiniApp('legacy-code');
        $requests->pop();

        self::assertSame($user->getId(), $authenticatedUser->getId());
        self::assertSame(1, $em->getRepository(WechatUser::class)->count(['appId' => 'wx-legacy-app', 'openid' => 'same-openid']));
    }

    public function testIdentityMigrationPromotesLegacyRowsAndCopiesAdditionalAppIds(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('wechat-migration@example.test')->setUsername('wechat-migration');
        $em->persist($user);
        $em->flush();

        $connection = $em->getConnection();
        $connection->executeStatement('CREATE TABLE store_wechat_identity (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, app_id VARCHAR(128) NOT NULL, openid VARCHAR(128) NOT NULL, app_type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL)');
        foreach (['wx-migration-a', 'wx-migration-b'] as $appId) {
            $connection->insert('store_wechat_identity', [
                'user_id' => $user->getId(),
                'app_id' => $appId,
                'openid' => 'migration-openid',
                'app_type' => WechatApplication::TYPE_OFFICIAL,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
        require_once dirname(__DIR__, 2).'/migrations/Version20261015100000.php';
        $migration = new \NsUltimate\Business\Wechat\Migrations\Version20261015100000($connection, new \Psr\Log\NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        require_once dirname(__DIR__, 2).'/migrations/Version20261015110000.php';
        $dropMigration = new \NsUltimate\Business\Wechat\Migrations\Version20261015110000($connection, new \Psr\Log\NullLogger());
        $dropMigration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($dropMigration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $userId = $user->getId();
        $em->clear();
        $user = $em->find(User::class, $userId);

        self::assertSame(2, $em->getRepository(WechatUser::class)->count(['user' => $user]));
        self::assertSame(1, $em->getRepository(WechatUser::class)->count([
            'user' => $user,
            'appId' => 'wx-migration-a',
            'openid' => 'migration-openid',
        ]));
        self::assertSame(1, $em->getRepository(WechatUser::class)->count([
            'user' => $user,
            'appId' => 'wx-migration-b',
            'openid' => 'migration-openid',
        ]));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['store_wechat_identity']));
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
        $service = new StoreWechatAuthService(
            new FakeWechatService(),
            self::getContainer()->get(StoreWechatResolver::class),
            $em,
            $requests,
            self::getContainer()->get(\App\Wechat\Repository\WechatUserRepository::class),
            '',
            '',
        );
        $requests->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeA->getUuid()], JSON_THROW_ON_ERROR)));
        $userA = $service->authenticateFromMiniApp('same-openid-code');
        $sameAppUser = $service->authenticateFromMiniApp('same-openid-code');
        $requests->pop();
        $requests->push(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['store_uuid' => $storeB->getUuid()], JSON_THROW_ON_ERROR)));
        $userB = $service->authenticateFromMiniApp('same-openid-code');
        $requests->pop();

        self::assertNotSame($userA->getId(), $userB->getId());
        self::assertSame($userA->getId(), $sameAppUser->getId());
        self::assertSame(2, $em->getRepository(WechatUser::class)->count(['openid' => 'same-openid']));
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

    private function testCertificate(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'wechat-test'], $key);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));

        return $pem;
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
