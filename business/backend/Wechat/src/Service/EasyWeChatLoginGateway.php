<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;

/**
 * EasyWeChat-backed login gateway built from a resolved Store configuration.
 */
final class EasyWeChatLoginGateway implements WechatLoginGatewayInterface
{
    public function __construct(private readonly WechatClientFactory $factory)
    {
    }

    public function miniappSession(ResolvedWechatConfig $config, string $jsCode): array
    {
        $app = $this->factory->miniApp($config);
        $response = $app->getClient()->get('/sns/jscode2session', [
            'query' => [
                'appid' => $app->getAccount()->getAppId(),
                'secret' => $app->getAccount()->getSecret(),
                'js_code' => $jsCode,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $data = $response->toArray(false);
        if (!isset($data['openid'])) {
            throw new \RuntimeException('WeChat code2Session failed: '.($data['errmsg'] ?? 'unknown error'));
        }

        return [
            'openid' => (string) $data['openid'],
            'unionid' => isset($data['unionid']) ? (string) $data['unionid'] : null,
            'session_key' => (string) ($data['session_key'] ?? ''),
        ];
    }

    public function phoneNumber(ResolvedWechatConfig $config, string $code): string
    {
        $response = $this->factory->miniApp($config)->getClient()->postJson('wxa/business/getuserphonenumber', [
            'code' => $code,
        ]);

        $data = $response->toArray(false);
        if (!isset($data['phone_info']['phoneNumber'])) {
            throw new \RuntimeException('WeChat getPhoneNumber failed: '.($data['errmsg'] ?? 'unknown error'));
        }

        return (string) $data['phone_info']['phoneNumber'];
    }

    public function oauthRedirectUrl(ResolvedWechatConfig $config, string $callbackUrl): string
    {
        $oauth = $this->factory->officialAccount($config)->getOauth();

        return $oauth->scopes(['snsapi_userinfo'])->redirect($callbackUrl);
    }

    public function oauthUser(ResolvedWechatConfig $config, string $code): array
    {
        try {
            $user = $this->factory->officialAccount($config)->getOauth()->userFromCode($code);
            $raw = $user->getRaw();

            return [
                'openid' => (string) $user->getId(),
                'unionid' => isset($raw['unionid']) ? (string) $raw['unionid'] : null,
                'nickname' => (string) ($user->getNickname() ?? ''),
                'avatar' => (string) ($user->getAvatar() ?? ''),
                'raw' => is_array($raw) ? $raw : [],
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('WeChat OAuth failed: '.$e->getMessage(), 0, $e);
        }
    }
}
