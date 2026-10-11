<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Support;

use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Service\WechatPayProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deterministic pay provider used by integration tests instead of the WeChat API.
 */
final class FakeWechatPayProvider implements WechatPayProviderInterface
{
    /** @var array<string, mixed> */
    public array $lastBody = [];

    /** @var array<string, mixed> */
    public array $notifyData = [];

    public function prepay(ResolvedWechatConfig $config, string $tradeType, array $body): array
    {
        $this->lastBody = $body;

        return $tradeType === WechatPaymentAttempt::TRADE_NATIVE
            ? ['code_url' => 'weixin://wxpay/bizpayurl?pr=abc', 'raw' => []]
            : ['prepay_id' => 'prepay-1', 'raw' => []];
    }

    public function buildJsapiConfig(ResolvedWechatConfig $config, string $prepayId, string $appId): array
    {
        return [
            'appId' => $appId,
            'timeStamp' => '1700000000',
            'nonceStr' => 'nonce',
            'package' => 'prepay_id='.$prepayId,
            'signType' => 'RSA',
            'paySign' => 'signature',
            'prepay_id' => $prepayId,
        ];
    }

    public function queryByOutTradeNo(ResolvedWechatConfig $config, string $outTradeNo): array
    {
        return ['out_trade_no' => $outTradeNo, 'trade_state' => 'SUCCESS'];
    }

    public function parseNotify(Request $request, ResolvedWechatConfig $config): array
    {
        return $this->notifyData;
    }
}
