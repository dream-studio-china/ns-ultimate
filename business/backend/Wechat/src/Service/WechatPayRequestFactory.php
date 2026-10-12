<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;

final class WechatPayRequestFactory
{
    /** @param array<string, mixed> $commonFields
     *  @return array{endpoint: string, body: array<string, mixed>}
     */
    public function createOrder(
        WechatMerchant $merchant,
        WechatApplication $application,
        string $tradeType,
        array $commonFields,
        ?string $openid = null,
    ): array {
        if (!in_array($tradeType, ['native', 'jsapi'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported WeChat trade type: %s', $tradeType));
        }

        if ($merchant->getPaymentMode() === WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER) {
            $body = $commonFields + [
                'sp_appid' => $merchant->getServiceProviderAppId(),
                'sp_mchid' => $merchant->getMerchantId(),
                'sub_mchid' => $merchant->getSubMerchantId(),
                'sub_appid' => $application->getAppId(),
            ];
            if ($tradeType === 'jsapi') {
                $body['payer'] = ['sub_openid' => $openid];
            }

            return [
                'endpoint' => 'v3/pay/partner/transactions/'.$tradeType,
                'body' => $body,
            ];
        }

        $body = $commonFields + [
            'appid' => $application->getAppId(),
            'mchid' => $merchant->getMerchantId(),
        ];
        if ($tradeType === 'jsapi') {
            $body['payer'] = ['openid' => $openid];
        }

        return [
            'endpoint' => 'v3/pay/transactions/'.$tradeType,
            'body' => $body,
        ];
    }

    public function notificationMatchesProfiles(WechatMerchant $merchant, WechatApplication $application, object $message): bool
    {
        if ($merchant->getPaymentMode() === WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER) {
            return ($message->sp_appid ?? null) === $merchant->getServiceProviderAppId()
                && ($message->sp_mchid ?? null) === $merchant->getMerchantId()
                && ($message->sub_mchid ?? null) === $merchant->getSubMerchantId()
                && ($message->sub_appid ?? null) === $application->getAppId();
        }

        return ($message->appid ?? null) === $application->getAppId()
            && ($message->mchid ?? null) === $merchant->getMerchantId();
    }

    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    public function createRefundBody(WechatMerchant $merchant, array $body): array
    {
        if ($merchant->getPaymentMode() === WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER) {
            $body['sub_mchid'] = $merchant->getSubMerchantId();
        }

        return $body;
    }
}
