<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;

/**
 * Provider boundary for WeChat login/phone/OAuth exchange.
 *
 * Kept as an interface so tests can substitute a deterministic fake instead of
 * calling the WeChat API.
 */
interface WechatLoginGatewayInterface
{
    /**
     * @return array{openid: string, unionid?: string|null}
     */
    public function miniappSession(ResolvedWechatConfig $config, string $jsCode): array;

    public function phoneNumber(ResolvedWechatConfig $config, string $code): string;

    public function oauthRedirectUrl(ResolvedWechatConfig $config, string $callbackUrl): string;

    /**
     * @return array{openid: string, unionid?: string|null, nickname?: string, avatar?: string}
     */
    public function oauthUser(ResolvedWechatConfig $config, string $code): array;
}
