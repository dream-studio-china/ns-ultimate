<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Exception\WechatConfigurationException;

/**
 * Builds WeChat SDK clients from a resolved, immutable Store configuration.
 *
 * Instances are memoized per resolved revision identity. There is no mutable
 * "current store" state: callers must pass an explicit ResolvedWechatConfig, so
 * long-running HTTP and worker processes cannot leak one Store into another.
 * Cached access tokens are namespaced per AppID.
 */
final class WechatClientFactory
{
    /** @var array<string, MiniApp> */
    private array $miniApps = [];

    /** @var array<string, OfficialAccount> */
    private array $officialAccounts = [];

    /** @var array<string, Pay> */
    private array $payApps = [];

    public function miniApp(ResolvedWechatConfig $config): MiniApp
    {
        if ($config->applicationType !== WechatApplication::TYPE_MINIAPP || $config->appSecret === null) {
            throw WechatConfigurationException::applicationMissing($config->storeUuid, $config->capability);
        }

        $key = $config->applicationRevisionUuid ?? $config->appId ?? '';
        if (!isset($this->miniApps[$key])) {
            $app = new MiniApp([
                'app_id' => $config->appId,
                'secret' => $config->appSecret,
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ]);
            $app->setCacheNamespace('wechat:miniapp:'.($config->appId ?? ''));
            $this->miniApps[$key] = $app;
        }

        return $this->miniApps[$key];
    }

    public function officialAccount(ResolvedWechatConfig $config): OfficialAccount
    {
        if ($config->applicationType !== WechatApplication::TYPE_OFFICIAL || $config->appSecret === null) {
            throw WechatConfigurationException::applicationMissing($config->storeUuid, $config->capability);
        }

        $key = $config->applicationRevisionUuid ?? $config->appId ?? '';
        if (!isset($this->officialAccounts[$key])) {
            $app = new OfficialAccount([
                'app_id' => $config->appId,
                'secret' => $config->appSecret,
                'token' => $config->officialToken ?? '',
                'aes_key' => $config->officialAesKey ?? '',
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ]);
            $app->setCacheNamespace('wechat:official:'.($config->appId ?? ''));
            $this->officialAccounts[$key] = $app;
        }

        return $this->officialAccounts[$key];
    }

    public function pay(ResolvedWechatConfig $config): Pay
    {
        if (
            $config->merchantId === null
            || $config->apiV3Key === null
            || $config->merchantPrivateKey === null
            || $config->merchantCertificate === null
        ) {
            throw WechatConfigurationException::merchantMissing($config->storeUuid, $config->capability);
        }

        $key = $config->merchantRevisionUuid ?? $config->merchantId;
        if (!isset($this->payApps[$key])) {
            $payConfig = [
                'mch_id' => (int) $config->merchantId,
                'secret_key' => $config->apiV3Key,
                'private_key' => $config->merchantPrivateKey,
                'certificate' => $config->merchantCertificate,
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ];

            if ($config->platformPublicKeyId !== null && $config->platformPublicKey !== null) {
                $payConfig['platform_certs'] = [$config->platformPublicKeyId => $config->platformPublicKey];
            }

            $this->payApps[$key] = new Pay($payConfig);
        }

        return $this->payApps[$key];
    }
}
