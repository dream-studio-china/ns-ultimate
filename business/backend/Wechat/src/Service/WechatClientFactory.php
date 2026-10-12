<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;

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

        return $this->payWithCredentials($application['profile']->getAppId(), $merchant['profile']->getMerchantId(), $this->paymentCredentials($merchant));
    }

    public function payByUuid(string $applicationUuid, string $merchantUuid, string $type): Pay
    {
        $application = $this->resolver->applicationByUuid($applicationUuid, $type);
        $merchant = $this->resolver->merchantByUuid($merchantUuid);

        return $this->payWithCredentials($application['profile']->getAppId(), $merchant['profile']->getMerchantId(), $this->paymentCredentials($merchant));
    }

    /** @param array{profile: WechatMerchant, credentials: array<string, string>} $merchant
     *  @return array<string, string>
     */
    private function paymentCredentials(array $merchant): array
    {
        return array_merge($merchant['credentials'], [
            'payment_mode' => $merchant['profile']->getPaymentMode(),
            'service_provider_app_id' => $merchant['profile']->getServiceProviderAppId() ?? '',
            'sub_merchant_id' => $merchant['profile']->getSubMerchantId() ?? '',
        ]);
    }

    /** @param array<string, string> $credentials */
    private function payWithCredentials(string $appId, string $merchantId, array $credentials): Pay
    {
        $serviceProviderMode = ($credentials['payment_mode'] ?? 'direct') === 'service_provider';
        if ($serviceProviderMode) {
            $appId = $credentials['service_provider_app_id'] ?? '';
        }
        $platformCertificate = $credentials['platform_certificate'] ?? '';
        if ($platformCertificate === '' || openssl_x509_read($platformCertificate) === false) {
            throw new \RuntimeException('A valid WeChat Pay platform certificate is required to verify callbacks and create payments.');
        }

        return new Pay([
            'app_id' => $appId,
            'mch_id' => $merchantId,
            'secret_key' => $credentials['api_v3_key'] ?? '',
            'private_key' => $credentials['private_key'] ?? '',
            'certificate' => $credentials['certificate'] ?? '',
            'platform_certs' => [$platformCertificate],
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
    }
}
