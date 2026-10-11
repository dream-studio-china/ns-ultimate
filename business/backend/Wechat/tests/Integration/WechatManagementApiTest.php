<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use NsUltimate\Business\Wechat\Command\SeedWechatAuthorizationCommand;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;

final class WechatManagementApiTest extends WechatApiTestCase
{
    public function testManagementRoutesAreRegistered(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        foreach ([
            'manage-wechat-applications-list' => '/manage/wechat-applications',
            'manage-wechat-application-revisions-list' => '/manage/wechat-application-revisions',
            'manage-wechat-merchants-list' => '/manage/wechat-merchants',
            'manage-wechat-merchant-revisions-list' => '/manage/wechat-merchant-revisions',
            'manage-store-wechat-settings-list' => '/manage/store-wechat-settings',
            'store-wechat-setting-list' => '/store/{scopeId}/wechat-setting',
        ] as $name => $path) {
            self::assertInstanceOf(Route::class, $routes->get($name), $name);
            self::assertSame($path, $routes->get($name)?->getPath());
            self::assertInstanceOf(Route::class, $routes->get('api-v1-'.$name), 'api-v1-'.$name);
            self::assertSame('/api/v1'.$path, $routes->get('api-v1-'.$name)?->getPath());
        }
    }

    public function testApplicationLifecycleNeverLeaksSecrets(): void
    {
        $applicationId = $this->createApplication('wx-mini-a');
        $revisionUuid = $this->publishApplicationRevision($applicationId, 'super-secret-value', ['shop.example.com']);

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-applications');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('super-secret-value', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret_encrypted', json_encode($body, JSON_THROW_ON_ERROR));

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-application-revisions');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('super-secret-value', json_encode($body, JSON_THROW_ON_ERROR));

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-application-revisions/'.$revisionUuid);
        self::assertSame(200, $status);
        self::assertSame('published', $body['data']['state']);
        self::assertTrue($body['data']['secret']);
        self::assertSame(['shop.example.com'], $body['data']['redirectHosts']);
        self::assertStringNotContainsString('super-secret-value', json_encode($body, JSON_THROW_ON_ERROR));

        $stored = $this->entityManager()->getConnection()->fetchOne(
            'SELECT secret_encrypted FROM wechat_application_revision WHERE uuid = ?',
            [$revisionUuid],
        );
        self::assertIsString($stored);
        self::assertStringNotContainsString('super-secret-value', $stored);
    }

    public function testApplicationUpdateAndDuplicateAppIdRejection(): void
    {
        $applicationId = $this->createApplication('wx-mini-a');
        [, $body] = $this->request('GET', '/api/v1/manage/wechat-applications/'.$this->applicationUuid($applicationId));
        $uuid = $body['data']['uuid'];

        [$status, $body] = $this->request('PUT', '/api/v1/manage/wechat-applications/'.$uuid, ['name' => 'Renamed', 'enabled' => false]);
        self::assertSame(200, $status, json_encode($body));
        self::assertFalse($body['data']['enabled']);

        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'Duplicate',
            'type' => 'miniapp',
            'appId' => 'wx-mini-a',
        ]);
        self::assertSame(400, $status, json_encode($body));
    }

    public function testMerchantLifecyclePublishesRevision(): void
    {
        $merchantId = $this->createMerchant('1900000001');
        $revisionUuid = $this->publishMerchantRevision($merchantId);

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-merchant-revisions/'.$revisionUuid);
        self::assertSame(200, $status);
        self::assertSame('published', $body['data']['state']);
        self::assertTrue($body['data']['privateKey']);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testStoreBindingAppliesToResolver(): void
    {
        $store = $this->createStore('A');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a');
        $merchantRevision = $this->publishMerchantRevision($this->createMerchant('1900000001'));

        $settingUuid = $this->createStoreSetting($store->getUuid(), $applicationRevision, $merchantRevision, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
            StoreWechatSetting::CAP_PAYMENT_JSAPI => true,
        ]);

        [$status, $body] = $this->request('GET', '/api/v1/manage/store-wechat-settings/'.$settingUuid);
        self::assertSame(200, $status, json_encode($body));
        self::assertTrue($body['data']['capabilities'][StoreWechatSetting::CAP_MINIAPP_LOGIN]);

        $config = self::getContainer()->get(StoreWechatResolver::class)
            ->resolve($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN);
        self::assertSame('wx-mini-a', $config->appId);
        self::assertSame('secret-a', $config->appSecret);
    }

    public function testBoundApplicationCannotBeDeleted(): void
    {
        $store = $this->createStore('A');
        $applicationId = $this->createApplication('wx-mini-a');
        $applicationRevision = $this->publishApplicationRevision($applicationId, 'secret-a');
        $this->createStoreSetting($store->getUuid(), $applicationRevision, null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);

        [$status, $body] = $this->request('DELETE', '/api/v1/manage/wechat-applications/'.$this->applicationUuid($applicationId));
        self::assertSame(409, $status, json_encode($body));
    }

    public function testManagementEndpointsRejectNonAdministrators(): void
    {
        [$status] = $this->request('GET', '/api/v1/manage/wechat-applications', token: $this->userToken(['ROLE_USER']));
        self::assertSame(403, $status);
    }

    public function testInvalidInputIsRejectedWithBadRequest(): void
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', ['name' => 'Missing type']);
        self::assertSame(400, $status, json_encode($body));
    }

    public function testUnknownResourceReturnsNotFound(): void
    {
        [$status] = $this->request('GET', '/api/v1/manage/wechat-applications/'.UUID::v4());
        self::assertSame(404, $status);
    }

    public function testAuthorizationSeedCommandIsIdempotent(): void
    {
        $command = self::getContainer()->get(SeedWechatAuthorizationCommand::class);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]));

        self::assertInstanceOf(
            Permission::class,
            $this->entityManager()->getRepository(Permission::class)->findOneBy(['code' => 'store:wechat:update']),
        );
        $role = $this->entityManager()->getRepository(Role::class)->findOneBy(['code' => 'store_wechat_manager']);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame(Role::SCOPE_STORE, $role->getScopeType());
    }

    private function applicationUuid(int $applicationId): string
    {
        return (string) $this->entityManager()
            ->getRepository(\NsUltimate\Business\Wechat\Entity\WechatApplication::class)
            ->find($applicationId)
            ?->getUuid();
    }
}
