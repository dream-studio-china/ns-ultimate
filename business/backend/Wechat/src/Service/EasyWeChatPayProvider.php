<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * EasyWeChat-backed pay provider built from a resolved Store configuration.
 */
final class EasyWeChatPayProvider implements WechatPayProviderInterface
{
    public function __construct(
        private readonly WechatClientFactory $factory,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
    ) {
    }

    public function prepay(ResolvedWechatConfig $config, string $tradeType, array $body): array
    {
        $url = $tradeType === WechatPaymentAttempt::TRADE_NATIVE
            ? 'v3/pay/transactions/native'
            : 'v3/pay/transactions/jsapi';

        $data = $this->factory->pay($config)->getClient()->postJson($url, $body)->toArray(false);

        return [
            'prepay_id' => isset($data['prepay_id']) ? (string) $data['prepay_id'] : null,
            'code_url' => isset($data['code_url']) ? (string) $data['code_url'] : null,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    public function buildJsapiConfig(ResolvedWechatConfig $config, string $tradeType, string $prepayId, string $appId): array
    {
        $utils = $this->factory->pay($config)->getUtils();

        return $tradeType === WechatPaymentAttempt::TRADE_OFFICIAL_JSAPI
            ? $utils->buildSdkConfig($prepayId, $appId, 'RSA')
            : $utils->buildMiniAppConfig($prepayId, $appId, 'RSA');
    }

    public function queryByOutTradeNo(ResolvedWechatConfig $config, string $outTradeNo): array
    {
        $app = $this->factory->pay($config);
        $data = $app->getClient()->get('v3/pay/transactions/out-trade-no/'.rawurlencode($outTradeNo), [
            'query' => ['mchid' => (string) $app->getMerchant()->getMerchantId()],
        ])->toArray(false);

        return is_array($data) ? $data : [];
    }

    public function parseNotify(Request $request, ResolvedWechatConfig $config): array
    {
        $app = $this->factory->pay($config);
        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $message = null;

        try {
            $app->getServer()->handlePaid(function ($msg, \Closure $next) use ($app, $psrRequest, &$message) {
                $app->getValidator()->validate($psrRequest);
                $message = $msg;

                return $next($msg);
            });
        } catch (\Throwable $e) {
            throw new PaymentVerificationException('WeChat notify verification failed: '.$e->getMessage(), 0, $e);
        }

        if ($message === null) {
            throw new PaymentVerificationException('Unsupported or invalid WeChat notify payload.');
        }

        $data = $message->toArray();

        return is_array($data) ? $data : [];
    }

    public function createRefund(ResolvedWechatConfig $config, array $body): array
    {
        $url = $config->merchantMode === WechatMerchant::MODE_PARTNER
            ? 'v3/refund/partner/refunds'
            : 'v3/refund/domestic/refunds';

        $data = $this->factory->pay($config)->getClient()->postJson($url, $body)->toArray(false);
        if (!is_array($data)) {
            throw new \RuntimeException('WeChat refund returned an invalid response.');
        }

        return [
            'refund_id' => isset($data['refund_id']) ? (string) $data['refund_id'] : null,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'raw' => $data,
        ];
    }

    public function queryRefund(ResolvedWechatConfig $config, string $outRefundNo): array
    {
        $app = $this->factory->pay($config);
        $query = ['mchid' => (string) $app->getMerchant()->getMerchantId()];
        if ($config->merchantMode === WechatMerchant::MODE_PARTNER) {
            $query['sub_mchid'] = (string) $config->subMerchantId;
        }
        $data = $app->getClient()->get('v3/refund/domestic/refunds/'.rawurlencode($outRefundNo), [
            'query' => $query,
        ])->toArray(false);

        return is_array($data) ? $data : [];
    }

    public function parseRefundNotify(Request $request, ResolvedWechatConfig $config): array
    {
        $app = $this->factory->pay($config);
        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $message = null;

        try {
            $app->getServer()->handleRefunded(function ($msg, \Closure $next) use ($app, $psrRequest, &$message) {
                $app->getValidator()->validate($psrRequest);
                $message = $msg;

                return $next($msg);
            });
        } catch (\Throwable $e) {
            throw new PaymentVerificationException('WeChat refund notify verification failed: '.$e->getMessage(), 0, $e);
        }

        if ($message === null) {
            throw new PaymentVerificationException('Unsupported or invalid WeChat refund notify payload.');
        }

        $data = $message->toArray();

        return is_array($data) ? $data : [];
    }
}
