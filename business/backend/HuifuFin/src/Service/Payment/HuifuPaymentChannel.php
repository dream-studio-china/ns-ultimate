<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

/** Keep configured methods and provider trade types paired across payment entry points. */
final class HuifuPaymentChannel
{
    public static function tradeType(string $channel): string
    {
        return match ($channel) {
            'jspay', 'quickpay' => 'T_JSAPI',
            'native' => 'T_NATIVE',
            'alipay' => 'A_NATIVE',
            default => throw new \InvalidArgumentException('Unsupported Huifu payment channel.'),
        };
    }
}
