<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;
use NsUltimate\Business\Wechat\Entity\WechatApplication;

final class WechatClientFactory
{
    public function __construct(private readonly StoreWechatResolver $resolver)
    {
    }

    public function miniapp(string $storeUuid): MiniApp
    {
        $resolved = $this->resolver->application($storeUuid, WechatApplication::TYPE_MINIAPP);

        return new MiniApp([
            'app_id' => $resolved['profile']->getAppId(),
            'secret' => $resolved['credentials']['secret'] ?? '',
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
    }

    public function official(string $storeUuid): OfficialAccount
    {
        $resolved = $this->resolver->application($storeUuid, WechatApplication::TYPE_OFFICIAL);

        return new OfficialAccount([
            'app_id' => $resolved['profile']->getAppId(),
            'secret' => $resolved['credentials']['secret'] ?? '',
            'token' => $resolved['credentials']['token'] ?? '',
            'aes_key' => $resolved['credentials']['aes_key'] ?? '',
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
    }

    public function pay(string $storeUuid, string $type): Pay
    {
        $application = $this->resolver->application($storeUuid, $type);
        $merchant = $this->resolver->merchant($storeUuid);

        return $this->payWithCredentials($application['profile']->getAppId(), $merchant['profile']->getMerchantId(), $merchant['credentials']);
    }

    public function payByUuid(string $applicationUuid, string $merchantUuid, string $type): Pay
    {
        $application = $this->resolver->applicationByUuid($applicationUuid, $type);
        $merchant = $this->resolver->merchantByUuid($merchantUuid);

        return $this->payWithCredentials($application['profile']->getAppId(), $merchant['profile']->getMerchantId(), $merchant['credentials']);
    }

    /** @param array<string, string> $credentials */
    private function payWithCredentials(string $appId, string $merchantId, array $credentials): Pay
    {

        return new Pay([
            'app_id' => $appId,
            'mch_id' => $merchantId,
            'secret_key' => $credentials['api_v3_key'] ?? '',
            'private_key' => $credentials['private_key'] ?? '',
            'certificate' => $credentials['certificate'] ?? '',
            'platform_certs' => isset($credentials['platform_certificate']) ? [$credentials['platform_certificate']] : [],
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
    }
}
