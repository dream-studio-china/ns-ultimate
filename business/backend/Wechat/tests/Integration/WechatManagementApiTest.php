<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Wechat\Command\SeedWechatAuthorizationCommand;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

final class WechatManagementApiTest extends KernelTestCase
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

    public function testManagementRoutesAreRegistered(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        foreach ([
            'business-wechat-applications-list' => '/manage/wechat-applications',
            'business-wechat-merchants-list' => '/manage/wechat-merchants',
            'business-store-wechat-settings-list' => '/manage/store-wechat-settings',
            'store-wechat-setting-detail' => '/store/{scopeId}/wechat-setting',
        ] as $name => $path) {
            self::assertInstanceOf(Route::class, $routes->get($name), $name);
            self::assertSame($path, $routes->get($name)?->getPath());
            self::assertInstanceOf(Route::class, $routes->get('api-v1-'.$name), 'api-v1-'.$name);
            self::assertSame('/api/v1'.$path, $routes->get('api-v1-'.$name)?->getPath());
        }
    }

    public function testAdministratorCanManageApplicationsWithoutLeakingSecrets(): void
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'Mini A',
            'type' => 'miniapp',
            'appId' => 'wx-mini-a',
        ]);
        self::assertSame(201, $status, json_encode($body));
        $applicationUuid = $body['data']['uuid'];

        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications/'.$applicationUuid.'/revisions', [
            'secret' => 'super-secret-value',
        ]);
        self::assertSame(201, $status, json_encode($body));
        self::assertSame('draft', $body['data']['state']);
        self::assertTrue($body['data']['hasSecret']);
        self::assertStringNotContainsString('super-secret-value', json_encode($body, JSON_THROW_ON_ERROR));

        $revisionUuid = $body['data']['uuid'];
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications/'.$applicationUuid.'/revisions/'.$revisionUuid.'/publish');
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('published', $body['data']['state']);

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-applications');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('super-secret-value', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret_encrypted', json_encode($body, JSON_THROW_ON_ERROR));

        [$status, $body] = $this->request('GET', '/api/v1/manage/wechat-applications/'.$applicationUuid);
        self::assertSame(200, $status);
        self::assertCount(1, $body['data']['revisions']);
        self::assertSame('published', $body['data']['revisions'][0]['state']);
    }

    public function testAdministratorCanCreateAndPublishMerchant(): void
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-merchants', [
            'name' => 'Merchant A',
            'merchantId' => '1900000001',
        ]);
        self::assertSame(201, $status, json_encode($body));
        $merchantUuid = $body['data']['uuid'];

        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-merchants/'.$merchantUuid.'/revisions', [
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ]);
        self::assertSame(201, $status, json_encode($body));
        self::assertTrue($body['data']['hasPrivateKey']);
        self::assertTrue($body['data']['hasCertificate']);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($body, JSON_THROW_ON_ERROR));

        [$status, $body] = $this->request(
            'POST',
            '/api/v1/manage/wechat-merchants/'.$merchantUuid.'/revisions/'.$body['data']['uuid'].'/publish',
        );
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('published', $body['data']['state']);
    }

    public function testAdministratorCanBindPublishedRevisionsAndResolverAppliesThem(): void
    {
        $applicationRevision = $this->publishApplication('wx-mini-a', 'secret-a');
        $merchantRevision = $this->publishMerchant('1900000001');
        $storeUuid = UUID::v4();

        [$status, $body] = $this->request('POST', '/api/v1/manage/store-wechat-settings/store/'.$storeUuid.'/revisions', [
            'miniappRevisionUuid' => $applicationRevision,
            'merchantRevisionUuid' => $merchantRevision,
            'capabilities' => [
                StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true,
                StoreWechatSettingRevision::CAP_PAYMENT_JSAPI => true,
            ],
        ]);
        self::assertSame(201, $status, json_encode($body));
        self::assertSame('draft', $body['data']['state']);

        [$status, $body] = $this->request(
            'POST',
            '/api/v1/manage/store-wechat-settings/store/'.$storeUuid.'/revisions/'.$body['data']['uuid'].'/publish',
        );
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('published', $body['data']['state']);

        [$status, $body] = $this->request('GET', '/api/v1/manage/store-wechat-settings/store/'.$storeUuid);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('published', $body['data']['published']['state']);
        self::assertTrue($body['data']['published']['capabilities'][StoreWechatSettingRevision::CAP_MINIAPP_LOGIN]);

        $config = self::getContainer()->get(StoreWechatResolver::class)
            ->resolve($storeUuid, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN);
        self::assertSame('wx-mini-a', $config->appId);
        self::assertSame('secret-a', $config->appSecret);
    }

    public function testManagementEndpointsRejectNonAdministrators(): void
    {
        [$status] = $this->request('GET', '/api/v1/manage/wechat-applications', token: $this->userToken(['ROLE_USER']));
        self::assertSame(403, $status);
    }

    public function testInvalidInputIsRejectedWithBadRequest(): void
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', ['name' => 'Missing type']);
        self::assertSame(400, $status);
        self::assertSame(400, $body['code']);
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

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(Permission::class, $entityManager->getRepository(Permission::class)->findOneBy(['code' => 'store:wechat:update']));
        $role = $entityManager->getRepository(Role::class)->findOneBy(['code' => 'store_wechat_manager']);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame(Role::SCOPE_STORE, $role->getScopeType());
    }

    private function publishApplication(string $appId, string $secret): string
    {
        [, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'Mini '.$appId,
            'type' => 'miniapp',
            'appId' => $appId,
        ]);
        $applicationUuid = $body['data']['uuid'];

        [, $body] = $this->request('POST', '/api/v1/manage/wechat-applications/'.$applicationUuid.'/revisions', [
            'secret' => $secret,
        ]);
        $revisionUuid = $body['data']['uuid'];

        $this->request('POST', '/api/v1/manage/wechat-applications/'.$applicationUuid.'/revisions/'.$revisionUuid.'/publish');

        return $revisionUuid;
    }

    private function publishMerchant(string $merchantId): string
    {
        [, $body] = $this->request('POST', '/api/v1/manage/wechat-merchants', [
            'name' => 'Merchant '.$merchantId,
            'merchantId' => $merchantId,
        ]);
        $merchantUuid = $body['data']['uuid'];

        [, $body] = $this->request('POST', '/api/v1/manage/wechat-merchants/'.$merchantUuid.'/revisions', [
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ]);
        $revisionUuid = $body['data']['uuid'];

        $this->request('POST', '/api/v1/manage/wechat-merchants/'.$merchantUuid.'/revisions/'.$revisionUuid.'/publish');

        return $revisionUuid;
    }

    private function userToken(array $roles): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('wechat-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('wechat-'.bin2hex(random_bytes(4)))
            ->setRoles($roles);
        $entityManager->persist($user);
        $entityManager->flush();

        return self::getContainer()->get(TokenManager::class)->createAccessToken($user);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $token ??= $this->adminToken();
        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = self::$kernel->handle($request);
        $content = $response->getContent();
        $body = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [$response->getStatusCode(), $body];
    }

    private function adminToken(): string
    {
        $this->adminToken ??= $this->userToken(['ROLE_ADMIN']);

        return $this->adminToken;
    }

    private ?string $adminToken = null;
}
