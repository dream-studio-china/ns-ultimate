<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Wechat\Service\WechatServiceInterface;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;
use Symfony\Component\HttpFoundation\RequestStack;

final class StoreAwareWechatService implements WechatServiceInterface
{
    public function __construct(
        private readonly WechatClientFactory $clients,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getMiniApp(): MiniApp { return $this->clients->miniapp($this->storeUuid()); }
    public function getOfficialAccount(): OfficialAccount { return $this->clients->official($this->storeUuid()); }
    public function getPayApp(): Pay { return $this->clients->pay($this->storeUuid(), $this->appType()); }

    public function setMiniApp(MiniApp $app): void { throw new \LogicException('Store WeChat clients are resolved from the request scope.'); }
    public function setOfficialAccount(OfficialAccount $app): void { throw new \LogicException('Store WeChat clients are resolved from the request scope.'); }
    public function setPayApp(Pay $app): void { throw new \LogicException('Store WeChat clients are resolved from the request scope.'); }

    public function code2Session(string $jsCode): array
    {
        $app = $this->getMiniApp();
        $response = $app->getClient()->get('/sns/jscode2session', ['query' => [
            'appid' => $app->getAccount()->getAppId(),
            'secret' => $app->getAccount()->getSecret(),
            'js_code' => $jsCode,
            'grant_type' => 'authorization_code',
        ]]);
        $data = $response->toArray(false);
        if (!isset($data['openid'])) {
            throw new \RuntimeException((string) ($data['errmsg'] ?? 'WeChat code2Session failed.'));
        }

        return ['openid' => (string) $data['openid'], 'unionid' => $data['unionid'] ?? null, 'session_key' => $data['session_key'] ?? ''];
    }

    public function getPhoneNumber(string $code): array
    {
        $data = $this->getMiniApp()->getClient()->postJson('wxa/business/getuserphonenumber', ['code' => $code])->toArray(false);
        if (!isset($data['phone_info']['phoneNumber'])) {
            throw new \RuntimeException((string) ($data['errmsg'] ?? 'WeChat phone lookup failed.'));
        }

        return ['phoneNumber' => (string) $data['phone_info']['phoneNumber']];
    }

    public function getOAuthRedirectUrl(string $callbackUrl): string
    {
        return $this->getOfficialAccount()->getOauth()->scopes(['snsapi_userinfo'])->redirect($callbackUrl);
    }

    public function getOAuthUser(string $code): array
    {
        $user = $this->getOfficialAccount()->getOauth()->userFromCode($code);
        $raw = $user->getRaw();

        return [
            'openid' => (string) $user->getId(),
            'unionid' => $raw['unionid'] ?? null,
            'nickname' => (string) ($user->getNickname() ?? ''),
            'avatar' => (string) ($user->getAvatar() ?? ''),
            'sex' => (int) ($raw['sex'] ?? 0),
            'province' => (string) ($raw['province'] ?? ''),
            'city' => (string) ($raw['city'] ?? ''),
            'country' => (string) ($raw['country'] ?? ''),
        ];
    }

    private function storeUuid(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $body = json_decode($request?->getContent() ?: '{}', true);
        $uuid = $request?->attributes->get('scopeId')
            ?? $request?->query->get('store_uuid')
            ?? (is_array($body) ? ($body['store_uuid'] ?? null) : null);
        if (!is_string($uuid) || $uuid === '') {
            throw new \RuntimeException('store_uuid is required for WeChat login.');
        }

        return $uuid;
    }

    private function appType(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $body = json_decode($request?->getContent() ?: '{}', true);
        return ($request?->query->get('app_type') ?? (is_array($body) ? ($body['app_type'] ?? null) : null)) === 'official'
            ? 'official'
            : 'miniapp';
    }
}
