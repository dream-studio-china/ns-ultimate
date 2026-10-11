<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared harness for the WeChat management integration tests.
 *
 * Drives the Core CRUD lifecycle over HTTP so the ApiView mixins, common
 * filters, authorization hooks, and serialization are all exercised.
 */
abstract class WechatApiTestCase extends KernelTestCase
{
    private ?string $adminToken = null;

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

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function request(string $method, string $path, ?array $payload = null, ?string $token = null): array
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

    protected function adminToken(): string
    {
        return $this->adminToken ??= $this->userToken(['ROLE_ADMIN']);
    }

    /** @param list<string> $roles */
    protected function userToken(array $roles): string
    {
        $user = (new User())
            ->setEmail('wechat-'.bin2hex(random_bytes(4)).'@example.test')
            ->setUsername('wechat-'.bin2hex(random_bytes(4)))
            ->setRoles($roles);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return self::getContainer()->get(TokenManager::class)->createAccessToken($user);
    }

    protected function createStore(string $code = 'STORE'): Store
    {
        $store = new Store($code.'-'.bin2hex(random_bytes(3)), 'Store '.$code);
        $this->entityManager()->persist($store);
        $this->entityManager()->flush();

        return $store;
    }

    protected function createApplication(string $appId): int
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'App '.$appId,
            'type' => 'miniapp',
            'appId' => $appId,
        ]);
        self::assertSame(201, $status, json_encode($body));

        return (int) $body['data']['id'];
    }

    protected function publishApplicationRevision(int $applicationId, string $secret, array $redirectHosts = []): string
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-application-revisions', [
            'application' => $applicationId,
            'secret' => $secret,
            'redirectHosts' => $redirectHosts,
        ]);
        self::assertSame(201, $status, json_encode($body));
        self::assertTrue($body['data']['secret']);

        $revisionUuid = $body['data']['uuid'];
        [$status, $body] = $this->request('PUT', '/api/v1/manage/wechat-application-revisions/'.$revisionUuid, [
            'state' => 'published',
        ]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('published', $body['data']['state']);

        return $revisionUuid;
    }

    protected function createMerchant(string $merchantId): int
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-merchants', [
            'name' => 'Merchant '.$merchantId,
            'merchantId' => $merchantId,
        ]);
        self::assertSame(201, $status, json_encode($body));

        return (int) $body['data']['id'];
    }

    protected function publishMerchantRevision(int $merchantId): string
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-merchant-revisions', [
            'merchant' => $merchantId,
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ]);
        self::assertSame(201, $status, json_encode($body));

        $revisionUuid = $body['data']['uuid'];
        [$status, $body] = $this->request('PUT', '/api/v1/manage/wechat-merchant-revisions/'.$revisionUuid, [
            'state' => 'published',
        ]);
        self::assertSame(200, $status, json_encode($body));

        return $revisionUuid;
    }

    /**
     * Create a store binding with the given capability map.
     *
     * @param array<string, bool> $capabilities
     */
    protected function createStoreSetting(string $storeUuid, ?string $miniappRevision, ?string $merchantRevision, array $capabilities): string
    {
        $payload = [
            'storeUuid' => $storeUuid,
            'capabilities' => $capabilities,
        ];
        if ($miniappRevision !== null) {
            $payload['miniappRevision'] = $miniappRevision;
        }
        if ($merchantRevision !== null) {
            $payload['merchantRevision'] = $merchantRevision;
        }

        [$status, $body] = $this->request('POST', '/api/v1/manage/store-wechat-settings', $payload);
        self::assertSame(201, $status, json_encode($body));

        return $body['data']['uuid'];
    }
}
