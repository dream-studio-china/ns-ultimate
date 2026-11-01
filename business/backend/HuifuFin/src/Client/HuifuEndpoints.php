<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Client;

final class HuifuEndpoints
{
    public const JSPAY = '/v2/trade/payment/jspay';
    public const QUICKPAY = '/v2/trade/onlinepayment/quickpay/frontpay';
    public const SCANPAY_QUERY = '/v2/trade/payment/scanpay/query';
    public const SCANPAY_REFUND = '/v2/trade/payment/scanpay/refund';
    public const SCANPAY_REFUND_QUERY = '/v2/trade/payment/scanpay/refundquery';
    public const SCANPAY_CLOSE = '/v2/trade/payment/scanpay/close';

    private function __construct()
    {
    }

    public static function forChannel(string $channel): string
    {
        return match ($channel) {
            'jspay', 'native' => self::JSPAY,
            'quickpay' => self::QUICKPAY,
            default => throw new \InvalidArgumentException(sprintf('Unsupported Huifu payment channel "%s".', $channel)),
        };
    }
}
