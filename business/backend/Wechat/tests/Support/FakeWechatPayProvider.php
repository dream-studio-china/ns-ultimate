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

    public function buildJsapiConfig(ResolvedWechatConfig $config, string $tradeType, string $prepayId, string $appId): array
    {
        return [
            'appId' => $appId,
            'timeStamp' => '1700000000',
            'nonceStr' => 'nonce',
            'package' => 'prepay_id='.$prepayId,
            'signType' => 'RSA',
            'paySign' => 'signature',
            'prepay_id' => $prepayId,
            'tradeType' => $tradeType,
        ];
    }

    public function queryByOutTradeNo(ResolvedWechatConfig $config, string $outTradeNo): array
    {
        return $this->queryResults[$outTradeNo] ?? [];
    }

    /** @var array<string, array<string, mixed>> */
    public array $queryResults = [];

    /**
     * @param array<string, mixed> $body
     * @return array{refund_id: string|null, status: string|null, raw: array<string, mixed>}
     */
    public function createRefund(ResolvedWechatConfig $config, array $body): array
    {
        $this->lastRefundBody = $body;

        return $this->refundResult;
    }

    /** @var array<string, mixed> */
    public array $lastRefundBody = [];

    /** @var array{refund_id: string|null, status: string|null, raw: array<string, mixed>} */
    public array $refundResult = ['refund_id' => 'refund-1', 'status' => 'SUCCESS', 'raw' => []];

    /** @var array<string, array<string, mixed>> */
    public array $refundQueryResults = [];

    public function queryRefund(ResolvedWechatConfig $config, string $outRefundNo): array
    {
        return $this->refundQueryResults[$outRefundNo] ?? [];
    }

    public function parseRefundNotify(Request $request, ResolvedWechatConfig $config): array
    {
        return $this->refundNotifyData;
    }

    /** @var array<string, mixed> */
    public array $refundNotifyData = [];

    public function parseNotify(Request $request, ResolvedWechatConfig $config): array
    {
        return $this->notifyData;
    }
}
