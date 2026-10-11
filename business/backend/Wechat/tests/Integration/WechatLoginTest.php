<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Wechat\Entity\WechatUser;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Command\ImportLegacyWechatIdentitiesCommand;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatIdentity;
use NsUltimate\Business\Wechat\Service\EasyWeChatLoginGateway;
use NsUltimate\Business\Wechat\Tests\Support\FakeWechatLoginGateway;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class WechatLoginTest extends WechatApiTestCase
{
    private FakeWechatLoginGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeWechatLoginGateway();
        self::getContainer()->set(EasyWechatLoginGateway::class, $this->gateway);
    }

    public function testMiniAppLoginResolvesIdentityAndReusesTheSameUser(): void
    {
        $store = $this->createStore('A');
        $this->createStoreSetting($store->getUuid(), $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'), null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);
        $this->gateway->sessions['code-1'] = $this->session('openid-1');

        [$status, $body] = $this->http('POST', $this->url($store, '/miniapp/login'), ['js_code' => 'code-1'], anonymous: true);
        self::assertSame(200, $status, json_encode($body));
        $firstUserId = $this->tokenUserId($body['access_token']);

        $this->gateway->sessions['code-2'] = $this->session('openid-1');
        [$status, $body] = $this->http('POST', $this->url($store, '/miniapp/login'), ['js_code' => 'code-2'], anonymous: true);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame($firstUserId, $this->tokenUserId($body['access_token']));

        $user = $this->entityManager()->find(User::class, $firstUserId);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testSameOpenidUnderDifferentAppsNeverCollides(): void
    {
        $storeA = $this->createStore('A');
        $storeB = $this->createStore('B');
        $this->createStoreSetting($storeA->getUuid(), $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'), null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);
        $this->createStoreSetting($storeB->getUuid(), $this->publishApplicationRevision($this->createApplication('wx-mini-b'), 'secret-b'), null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);
        $this->gateway->sessions['code-a'] = $this->session('shared-openid');
        $this->gateway->sessions['code-b'] = $this->session('shared-openid');

        [, $bodyA] = $this->http('POST', $this->url($storeA, '/miniapp/login'), ['js_code' => 'code-a'], anonymous: true);
        [, $bodyB] = $this->http('POST', $this->url($storeB, '/miniapp/login'), ['js_code' => 'code-b'], anonymous: true);

        self::assertNotSame($this->tokenUserId($bodyA['access_token']), $this->tokenUserId($bodyB['access_token']));
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM wechat_identity WHERE app_id = ? AND openid = ?',
            ['wx-mini-a', 'shared-openid'],
        ));
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM wechat_identity WHERE app_id = ? AND openid = ?',
            ['wx-mini-b', 'shared-openid'],
        ));
    }

    public function testMiniAppLoginFailsClosedForUnconfiguredStore(): void
    {
        [$status] = $this->http('POST', $this->url($this->createStore('A'), '/miniapp/login'), ['js_code' => 'code'], anonymous: true);
        self::assertSame(400, $status);
    }

    public function testPhoneBindingRequiresOwnedIdentityOfTheApplication(): void
    {
        $store = $this->createStore('A');
        $this->createStoreSetting($store->getUuid(), $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'), null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);
        $this->gateway->sessions['code-1'] = $this->session('openid-1');
        $this->gateway->phones['phone-code'] = '13800000000';

        [$status] = $this->http('POST', $this->url($store, '/miniapp/phone'), ['code' => 'phone-code'], anonymous: true);
        self::assertSame(401, $status);

        [$status] = $this->http('POST', $this->url($store, '/miniapp/phone'), ['code' => 'phone-code'], token: $this->userToken(['ROLE_USER']));
        self::assertSame(400, $status);

        [, $body] = $this->http('POST', $this->url($store, '/miniapp/login'), ['js_code' => 'code-1'], anonymous: true);
        [$status] = $this->http('POST', $this->url($store, '/miniapp/phone'), ['code' => 'phone-code'], token: $body['access_token']);
        self::assertSame(204, $status);

        $user = $this->entityManager()->find(User::class, $this->tokenUserId($body['access_token']));
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isPhoneVerified());
        self::assertSame('13800000000', $user->getPhone());
    }

    public function testOAuthUrlEnforcesRedirectAllowlist(): void
    {
        $store = $this->createStore('A');
        $this->createStoreSetting($store->getUuid(), null, null, [StoreWechatSetting::CAP_OFFICIAL_OAUTH => true], $this->publishOfficialRevision('wx-official-a', ['shop.example.com']));

        [$status, $body] = $this->http('GET', $this->url($store, '/oauth/url').'?'.http_build_query(['redirect_uri' => 'https://evil.example.net/cb']), anonymous: true);
        self::assertSame(400, $status, json_encode($body));

        [$status, $body] = $this->http('GET', $this->url($store, '/oauth/url').'?'.http_build_query(['redirect_uri' => 'https://shop.example.com/cb']), anonymous: true);
        self::assertSame(200, $status, json_encode($body));
        self::assertStringContainsString(urlencode('https://shop.example.com/cb'), $body['url']);
        self::assertStringContainsString(urlencode('state='), $body['url']);
    }

    public function testOAuthCallbackIsSingleUseAndRejectsSubstitutionAndExpiry(): void
    {
        $storeA = $this->createStore('A');
        $storeB = $this->createStore('B');
        $revisionA = $this->publishOfficialRevision('wx-official-a', ['shop.example.com']);
        $this->createStoreSetting($storeA->getUuid(), null, null, [StoreWechatSetting::CAP_OFFICIAL_OAUTH => true], $revisionA);
        $this->createStoreSetting($storeB->getUuid(), null, null, [StoreWechatSetting::CAP_OFFICIAL_OAUTH => true], $revisionA);
        $this->gateway->oauthUsers['oauth-code'] = ['openid' => 'oauth-openid', 'unionid' => null, 'raw' => []];

        $state = $this->issueState($storeA, 'https://shop.example.com/cb');

        // A callback against a different store must fail.
        [$status] = $this->http('POST', $this->url($storeB, '/oauth/callback'), ['code' => 'oauth-code', 'state' => $state], anonymous: true);
        self::assertSame(400, $status);

        [$status, $body] = $this->http('POST', $this->url($storeA, '/oauth/callback'), ['code' => 'oauth-code', 'state' => $state], anonymous: true);
        self::assertSame(200, $status, json_encode($body));

        // Replay of the consumed state must fail.
        [$status] = $this->http('POST', $this->url($storeA, '/oauth/callback'), ['code' => 'oauth-code', 'state' => $state], anonymous: true);
        self::assertSame(400, $status);

        // Expired state must fail.
        $expired = $this->issueState($storeA, 'https://shop.example.com/cb');
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE wechat_oauth_state SET expires_at = ? WHERE nonce = ?',
            ['2000-01-01 00:00:00', $expired],
        );
        [$status] = $this->http('POST', $this->url($storeA, '/oauth/callback'), ['code' => 'oauth-code', 'state' => $expired], anonymous: true);
        self::assertSame(400, $status);
    }

    public function testExplicitLinkRejectsIdentityOwnedByAnotherUser(): void
    {
        $store = $this->createStore('A');
        $this->createStoreSetting($store->getUuid(), $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'), null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);
        $this->gateway->sessions['code-owner'] = $this->session('openid-owned');
        $this->gateway->sessions['code-other'] = $this->session('openid-owned');

        [, $owner] = $this->http('POST', $this->url($store, '/miniapp/login'), ['js_code' => 'code-owner'], anonymous: true);

        $otherToken = $this->userToken(['ROLE_USER']);
        [$status, $body] = $this->http('POST', $this->url($store, '/miniapp/link'), ['js_code' => 'code-other'], token: $otherToken);
        self::assertSame(409, $status, json_encode($body));

        $identities = (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM wechat_identity WHERE app_id = ? AND openid = ?',
            ['wx-mini-a', 'openid-owned'],
        );
        self::assertSame(1, $identities);
        self::assertSame($this->tokenUserId($owner['access_token']), $this->identityUserId('wx-mini-a', 'openid-owned'));
    }

    public function testLegacyImportIsDryRunSafeAndIdempotent(): void
    {
        $entityManager = $this->entityManager();
        $user = (new User())->setEmail('legacy@example.test')->setUsername('legacy')->setRoles(['ROLE_USER']);
        $entityManager->persist($user);
        $entityManager->flush();
        $legacy = new WechatUser($user, 'legacy-openid', WechatUser::APP_TYPE_MINIAPP);
        $legacy->setUnionid('legacy-unionid');
        $entityManager->persist($legacy);
        $entityManager->flush();

        $command = self::getContainer()->get(ImportLegacyWechatIdentitiesCommand::class);
        $dryRun = new CommandTester($command);
        $dryRun->execute(['--app-id' => 'wx-mini-a', '--type' => 'miniapp', '--dry-run' => true]);
        self::assertSame(0, $dryRun->getStatusCode());
        self::assertSame(0, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM wechat_identity'));

        $apply = new CommandTester($command);
        $apply->execute(['--app-id' => 'wx-mini-a', '--type' => 'miniapp']);
        self::assertSame(0, $apply->getStatusCode());
        self::assertSame(1, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM wechat_identity'));
        self::assertSame($user->getId(), $this->identityUserId('wx-mini-a', 'legacy-openid'));

        $repeat = new CommandTester($command);
        $repeat->execute(['--app-id' => 'wx-mini-a', '--type' => 'miniapp']);
        self::assertSame(0, $repeat->getStatusCode());
        self::assertSame(1, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM wechat_identity'));
    }

    private function publishOfficialRevision(string $appId, array $redirectHosts): string
    {
        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-applications', [
            'name' => 'Official '.$appId,
            'type' => 'official',
            'appId' => $appId,
        ]);
        self::assertSame(201, $status, json_encode($body));
        $applicationId = (int) $body['data']['id'];

        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-application-revisions', [
            'application' => $applicationId,
            'secret' => 'official-secret',
            'officialToken' => 'official-token',
            'officialAesKey' => str_repeat('a', 43),
            'redirectHosts' => $redirectHosts,
        ]);
        self::assertSame(201, $status, json_encode($body));
        $revisionUuid = $body['data']['uuid'];

        [$status, $body] = $this->request('PUT', '/api/v1/manage/wechat-application-revisions/'.$revisionUuid, ['state' => 'published']);
        self::assertSame(200, $status, json_encode($body));

        return $revisionUuid;
    }

    /**
     * @param array<string, bool> $capabilities
     */
    protected function createStoreSetting(string $storeUuid, ?string $miniappRevision, ?string $merchantRevision, array $capabilities, ?string $officialRevision = null): string
    {
        $payload = ['storeUuid' => $storeUuid, 'capabilities' => $capabilities];
        if ($miniappRevision !== null) {
            $payload['miniappRevision'] = $miniappRevision;
        }
        if ($officialRevision !== null) {
            $payload['officialRevision'] = $officialRevision;
        }
        if ($merchantRevision !== null) {
            $payload['merchantRevision'] = $merchantRevision;
        }

        [$status, $body] = $this->request('POST', '/api/v1/manage/store-wechat-settings', $payload);
        self::assertSame(201, $status, json_encode($body));

        return $body['data']['uuid'];
    }

    private function issueState(Store $store, string $redirectUri): string
    {
        [, $body] = $this->http('GET', $this->url($store, '/oauth/url').'?'.http_build_query(['redirect_uri' => $redirectUri]), anonymous: true);
        parse_str((string) parse_url($body['url'], PHP_URL_QUERY), $query);
        self::assertIsString($query['redirect_uri'] ?? null);
        parse_str((string) parse_url($query['redirect_uri'], PHP_URL_QUERY), $inner);
        self::assertIsString($inner['state'] ?? null);

        return $inner['state'];
    }

    /** @return array{openid: string, unionid: null, session_key: string} */
    private function session(string $openid): array
    {
        return ['openid' => $openid, 'unionid' => null, 'session_key' => 'session-'.$openid];
    }

    private function url(Store $store, string $suffix): string
    {
        return '/api/v1/app/stores/'.$store->getUuid().'/wechat'.$suffix;
    }

    private function tokenUserId(string $token): int
    {
        $payload = self::getContainer()->get(TokenManager::class)->decodeAccessToken($token);
        self::assertIsArray($payload);

        return (int) $payload['sub'];
    }

    private function identityUserId(string $appId, string $openid): ?int
    {
        $identity = $this->entityManager()->getRepository(WechatIdentity::class)->findOneBy(['appId' => $appId, 'openid' => $openid]);

        return $identity?->getUser()?->getId();
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function http(string $method, string $path, ?array $payload = null, ?string $token = null, bool $anonymous = false): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (!$anonymous) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.($token ?? $this->adminToken());
        }

        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $headers,
            $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = self::$kernel->handle($request);
        $content = $response->getContent();
        $body = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [$response->getStatusCode(), $body];
    }
}
