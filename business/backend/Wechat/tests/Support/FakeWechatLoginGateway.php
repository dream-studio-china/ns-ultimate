<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Support;

use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Service\WechatLoginGatewayInterface;

/**
 * Deterministic login gateway used by integration tests instead of the WeChat API.
 */
final class FakeWechatLoginGateway implements WechatLoginGatewayInterface
{
    /** @var array<string, array{openid: string, unionid: string|null, session_key: string}> */
    public array $sessions = [];

    /** @var array<string, string> */
    public array $phones = [];

    /** @var array<string, array{openid: string, unionid: string|null, raw: array<string, mixed>}> */
    public array $oauthUsers = [];

    public function miniappSession(ResolvedWechatConfig $config, string $jsCode): array
    {
        if (!isset($this->sessions[$jsCode])) {
            throw new \RuntimeException('Invalid js_code.');
        }

        return $this->sessions[$jsCode];
    }

    public function phoneNumber(ResolvedWechatConfig $config, string $code): string
    {
        if (!isset($this->phones[$code])) {
            throw new \RuntimeException('Invalid phone code.');
        }

        return $this->phones[$code];
    }

    public function oauthRedirectUrl(ResolvedWechatConfig $config, string $callbackUrl): string
    {
        return 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='.$config->appId.'&redirect_uri='.urlencode($callbackUrl).'&response_type=code&scope=snsapi_userinfo';
    }

    public function oauthUser(ResolvedWechatConfig $config, string $code): array
    {
        if (!isset($this->oauthUsers[$code])) {
            throw new \RuntimeException('Invalid oauth code.');
        }

        return $this->oauthUsers[$code];
    }
}
